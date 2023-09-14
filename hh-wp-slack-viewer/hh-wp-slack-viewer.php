<?php

/**
 * Plugin Name: Slack Archive Viewer
 * Version: 0.1
 * Author: Mark Thompson
 */

namespace HitchinHackspace\SlackViewer;

use Throwable;
use ZipArchive, Exception;

// Represents a single slack export (currently represented by one zip file)
class SlackArchive {
   // A ZipArchive instance containing the export.
   private $archive;

   // Slack user cache.
   private $users = null;

   // Construct an archive, given an ID of an item in the media library.
   function __construct($archive_id) {
      $this->archive = new ZipArchive();
      if ($this->archive->open(get_attached_file($archive_id)) !== true)
         throw new Exception('There was a problem opening the Slack archive file.');
   }

   // Get a list of the files contained within this archive.
   function getFileList() {
      for ($i = 0; $i < count($this->archive); ++$i)
         yield $this->archive->getNameIndex($i);
   }

   // Get the contents of a member of the archive, decoded as JSON.
   function getJSON($path) {
      $content = $this->archive->getFromName($path);
      if ($content === false)
         throw new Exception("The Slack archive does not contain the requested file: $path");
   
      return json_decode($content, true, 128, JSON_THROW_ON_ERROR);
   }

   // Get the list of channels contained within this archive.
   function getChannelList() {
      $channels = $this->getJSON('channels.json');
      return array_map(function ($channel) { return new SlackChannel($this, $channel['name']); }, $channels);
   }

   // Get the specific channel named, or 'null' if it's not present.
   function getChannel($name) {
      $channels = $this->getChannelList();
      foreach ($channels as $channel)
         if ($channel->getName() == $name)
            return $channel;

      return null;
   }

   // Get all users in this archive.
   function getUsers() {
      if (!$this->users) {
         $users = $this->getJSON('users.json');
         $extract = function($users) {
            foreach ($users as $user) {
               $user = new SlackUser($this, $user);
               yield $user->getID() => $user;
            }
         };
         $this->users = iterator_to_array($extract($users));
      }
      return $this->users;
   }

   // Get a user by their ID.
   function getUser($id) {
      return $this->getUsers()[$id] ?? null;
   }
}

// Represents a Slack user.
class SlackUser {
   // A reference to the containing SlackArchive.
   private $archive;
   // The backing JSON object.
   public $obj;
   // Avatar image URLs
   private $avatarURLs = null;

   function __construct($archive, $obj) {
      $this->archive = $archive;
      $this->obj = $obj;
   }

   private static function getValueInner($obj, $key) {
      if (!$obj)
         return null;

      if (is_array($key))
         return array_map(function ($key) use ($obj) { return self::getValueInner($obj, $key); }, $key);
      
      return $obj[$key] ?? null;
   }

   private function getValue($key) { 
      return self::getValueInner($this->obj, $key);   
   }

   function getID() {
      return $this->getValue('id');
   }

   function getProfile($key = null) {
      $profile = $this->getValue('profile');
      return self::getValueInner($profile, $key);
   }

   function getDisplayName() {
      return $this->getProfile('display_name');
   }

   private static function getAvatarKeys() {
      return [
         '1024' => 'image_1024',
         '512' => 'image_512',
         '192' => 'image_192',
         '72' => 'image_72',
         '48' => 'image_48',
         '32' => 'image_32',
         '24' => 'image_24'
      ];
   }

   function getAvatarURLs() {
      if ($this->avatarURLs === null) {
         $fn = function() {
            foreach (self::getAvatarKeys() as $size => $key) {
               $value = $this->getProfile($key);
               if (!$value)
                  continue;
               yield $size => $value;
            }
         };

         $this->avatarURLs = iterator_to_array($fn());
      }
      
      return $this->avatarURLs;
      
   }

   function getAvatarURL($atleast = null) {
      $avatarURLs = $this->getAvatarURLs();

      $best = null;

      foreach ($avatarURLs as $size => $url) {
         if ($atleast === null)
            return $url;

         if ($size < $atleast)
            return $best;

         $best = $url;
      }

      return null;
   }
}

// Represents a single Slack channel.
class SlackChannel {
   // A reference to the containing SlackArchive.
   private $archive;
   // The name of this channel.
   private $name;

   function __construct($archive, $name) {
      $this->archive = $archive;
      $this->name = $name;
   }

   function getName() { return $this->name; }

   // Get a list of all the files in the archive relating to the message content of this channel.
   function getFiles() {
      $prefix = "{$this->name}/";

      foreach ($this->archive->getFileList() as $file)
         if (starts_with($file, $prefix) && ends_with($file, '.json'))
            yield $file;
   }

   // Get all the messages from this channel.
   function getContent() {
      $content = [];

      foreach ($this->getFiles() as $file)
         $content = array_merge($content, $this->archive->getJSON($file));

      return $content;
   }
}

// Does the string $a start with $b?
function starts_with($a, $b) {
   return substr($a, 0, strlen($b)) == $b;
}

// Does the string $a end with $b?
function ends_with($a, $b) {
   return substr($a, -strlen($b)) == $b;
}

// Render the content of a file in templates/, optionally bringing additional variables into scope.
function render_template($template, $args = []) {
   ob_start();
   try {
      extract($args);
      require(__DIR__ . "/templates/$template");
      return ob_get_contents();
   }
   finally {
      ob_clean();
   }
}

// Construct a SlackArchive instance based on the file selected in the admin page.
function get_slack_archive() {
   static $slack_archive = null;

   if (!$slack_archive) {
      // Get the archive an admin has uploaded.
      $archive_id = get_option('hh_slack_archives')['archive_id'] ?? 0;

      // Is there one?
      if (!$archive_id)
         throw new Exception('Sorry, the Slack archives are not available at this time.');

      $slack_archive = new SlackArchive($archive_id);
   }

   return $slack_archive;
}

// Render the contents of the slack archive, based on the path the user has selected (in the query)
add_shortcode('hh_slack_archives', function($attrs) {
   add_filter('jetpack_photon_skip_image', function() { return true; });

   ob_start();

   try {
      try {
         // Load the Slack archive
         $archive = get_slack_archive();

         // Work out which page the user wants.
         $channel = get_query_var('path') ?: '';

         // Is it a request for the channel list?
         if (!$channel) {
            $content = render_template('channel-list.php', ['archive' => $archive]);
         }
         else {
            // Does the channel exist?
            $channel = $archive->getChannel($channel);

            if (!$channel)
               throw new Exception('No such channel.');

            $content = render_template('channel-content.php', ['archive' => $archive, 'channel' => $channel]);
         }
      }
      catch (Throwable $e) {
         error_log(print_r($e, true));
         $content = $e->getMessage();
      }

      ?>
         <div class="slack-archives">
            <?= $content ?>
         </div>
      <?php
   }
   finally {
      return ob_get_clean();
   }
});

// Allow the 'path' query parameter to get to us.
add_filter('query_vars', function($query_vars) {
   $query_vars[] = 'path';

   return $query_vars;
});

// Create a Settings field that allows selection of a media library item.
function setting_media($page, $section, $option_group, $key, $name, $description) {
   $field_id = $option_group . '_' . $key;

   add_settings_field($field_id, $name, function() use ($option_group, $key, $description) {
      $options = get_option($option_group);
      $media_id = $options[$key] ?: 0;

      ?>
         <div class="select-media-container">
            <input type="hidden" name="<?= $option_group ?>[<?= $key ?>]" value="<?= $media_id ?>">
            <span><?= $media_id ? basename(get_attached_file($media_id)) : 'None Selected' ?></span>
            <button class="select-media" onclick="selectMedia(event);">Select File</button>
         </div>
         <p><?= $description ?></p>
      <?php
   }, $page, $section);
}

// Add a section/setting to the General Settings page to allow a Slack archive to be selected.
add_action('admin_init', function() {
   register_setting('general', 'hh_slack_archives', [
       'default' => [
           'archive_id' => 0
       ]
   ]);

   add_settings_section('hh_slack_archives', 'Slack Archive Viewer', function($args) {
      wp_enqueue_media();
      ?>
         <script>
            
            function selectMedia(ev) {
               ev.preventDefault();

               const el = ev.target;
               const container = el.closest('.select-media-container');
               const idInput = container.querySelector('input[type="hidden"]');

               let mediaPicker = wp.media({
                  title: 'Select or Upload a Slack HTML archive (.zip)',
                  button: {
                     text: 'Use this file'
                  },
                  multiple: false
               });

               mediaPicker.on('select', function() {
                  const selected = mediaPicker.state().get('selection').first().toJSON();

                  idInput.value = selected.id;
                  container.querySelector('span').innerText = selected.filename;
               });

               mediaPicker.open();
            }
         </script>
      <?php
   }, 'general');

   setting_media('general', 'hh_slack_archives', 'hh_slack_archives', 'archive_id', 'Slack Archive file', 'ZIP file containing the Slack archive in HTML format.');
});

add_action('wp_enqueue_scripts', function() {
   wp_enqueue_style('hh-wp-slack-viewer', plugins_url('style.css', __FILE__));
});