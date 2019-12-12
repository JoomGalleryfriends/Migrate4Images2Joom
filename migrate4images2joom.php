<?php
/******************************************************************************\
**   JoomGallery 4Images migration script 3.0                                 **
**   By: JoomGallery::ProjectTeam                                             **
**   Copyright (C) 2011 - 2019  M. Andreas Boettcher,                         **
**                              JoomGallery::ProjectTeam                      **
**   Released under GNU GPL Public License                                    **
**   License: http://www.gnu.org/copyleft/gpl.html or have a look             **
**   at administrator/components/com_joomgallery/LICENSE.TXT                  **
\******************************************************************************/

/*******************************************************************************
**   Migration of DB and Files from 4Images gallery to Joomgallery            **
**   On the fly generating of categories in db and file system                **
**   moving the images in the new categories                                  **
*******************************************************************************/

defined('_JEXEC') or die('Direct Access to this location is not allowed.');

class JoomMigrate4Images2Joom extends JoomMigration
{
  /**
   * The name of the migration
   * (should be unique)
   *
   * @var   string
   * @since 3.0
   */
  protected $migration = '4images2joom';

  /**
   * Properties for paths and database table names of old JoomGallery to migrate from
   *
   * @var   string
   * @since 1.6
   */
  protected $path_originals;
  protected $path_thumbnails;
  protected $table_images;
  protected $table_categories;
  protected $table_comments;
  protected $table_users;

  /**
   * Constructor
   *
   * @return  void
   * @since   1.6
   */
  public function __construct()
  {
    parent::__construct();

    // Create the image paths and table names
    $prefix = $this->getStateFromRequest('prefix', 'prefix', '', 'cmd');
    $path   = $this->getStateFromRequest('path', 'path', '', 'string');
    $this->path_originals     = JPath::clean($path.'/data/media/');
    $this->path_thumbnails    = JPath::clean($path.'/data/thumbnails/');
    $this->table_images       = $prefix.'images';
    $this->table_categories   = $prefix.'categories';
    $this->table_comments     = $prefix.'comments';
    $this->table_users        = $prefix.'users';
  }

  /**
   * Checks requirements for migration
   *
   * @return  void
   * @since   1.6
   */
  public function check($dirs = array(), $tables = array(), $xml = false, $min_version = false, $max_version = false)
  {
    $tables = array($this->table_images,
                    $this->table_categories,
                    $this->table_comments,
                    $this->table_users);

    $dirs = array($this->path_originals,
                  $this->path_thumbnails);

    parent::check($dirs, $tables, $xml, $min_version, $max_version);
  }

  /**
   * Main migration function
   *
   * @return  void
   * @since   1.6
   */
  protected function doMigration()
  {
    $task = $this->getTask('categories');

    switch($task)
    {
      case 'categories':
        $this->migrateCategories();
        // Break intentionally omited
      case 'rebuild':
        $this->rebuild();
        // Break intentionally omited
      case 'images':
        $this->migrateImages();
        // Break intentionally omited
      case 'comments':
        $this->migrateComments();
        // Break intentionally omited
      default:
        break;
    }
  }

  /**
   * Returns the maximum category ID of 4Images gallery.
   *
   * @return  int The maximum category ID of 4Images gallery
   * @since   1.6
   */
  protected function getMaxCategoryId()
  {
    $query = $this->_db2->getQuery(true)
          ->select('MAX(cat_id)')
          ->from($this->table_categories);
    $this->_db2->setQuery($query);

    return $this->runQuery('loadResult', $this->_db2);
  }

  /**
   * Migrates all categories
   *
   * @return  void
   * @since   1.6
   */
  protected function migrateCategories()
  {
    $query = $this->_db2->getQuery(true)
          ->select('*')
          ->from($this->table_categories);
    $this->prepareTable($query, $this->table_categories, 'cat_parent_id', array(0));

    while($cat = $this->getNextObject())
    {
      // Make information accessible for JoomGallery
      $cat->cid         = $cat->cat_id;
      $cat->name        = $cat->cat_name;
      $cat->description = $cat->cat_description;
      $cat->parent_id   = $cat->cat_parent_id;
      $cat->ordering    = $cat->cat_order;
      $cat->published   = 1;

      $this->createCategory($cat);

      $this->markAsMigrated($cat->cat_id, 'cat_id', $this->table_categories);

      if(!$this->checkTime())
      {
        $this->refresh();
      }
    }

    $this->resetTable($this->table_categories);
  }

  /**
   * Migrates all images
   *
   * @return  void
   * @since   1.6
   */
  protected function migrateImages()
  {
    $query = $this->_db2->getQuery(true)
          ->select('i.*, u.user_name')
          ->from($this->table_images.' AS i')
          ->leftJoin($this->table_users.' AS u ON i.user_id = u.user_id');
    $this->prepareTable($query);

    while($row = $this->getNextObject())
    {
      $original   = $this->path_originals.$row->cat_id.'/'.$row->image_media_file;
      $thumbnail  = $this->path_thumbnails.$row->cat_id.'/'.$row->image_thumb_file;

      $row->id          = $row->image_id;
      $row->catid       = $row->cat_id;
      $row->imgtitle    = $row->image_name;
      $row->imgtext     = $row->image_description;
      $row->imgdate     = JFactory::getDate($row->image_date)->toSql();
      $row->published   = $row->image_active;
      $row->imgfilename = $row->image_media_file;
      $row->imgvotes    = $row->image_votes;
      $row->imgvotesum  = $row->image_votes * $row->image_rating;
      $row->hits        = $row->image_hits;
      $row->imgauthor   = $row->user_name;

      $this->moveAndResizeImage($row, $original, null, $thumbnail, true);

      if(!$this->checkTime())
      {
        $this->refresh('images');
      }
    }

    $this->resetTable();
  }

  /**
   * Migrate all comments
   *
   * @return  void
   * @since   1.6
   */
  protected function migrateComments()
  {
    $query = $this->_db2->getQuery(true)
          ->select('*')
          ->from($this->table_comments);
    $this->prepareTable($query);

    while($row = $this->getNextObject())
    {
      $row->cmtid       = $row->comment_id;
      $row->cmtpic      = $row->image_id;
      $row->cmtname     = $row->user_name;
      $row->cmttext     = '[b]'.$row->comment_headline.'[/b]'."\n\n".$row->comment_text;
      $row->cmtip       = $row->comment_ip;
      $row->cmtdate     = JFactory::getDate($row->comment_date)->toSql();
      $row->published   = 1;

      $this->createComment($row);

      if(!$this->checkTime())
      {
        $this->refresh('comments');
      }
    }

    $this->resetTable();
  }
}