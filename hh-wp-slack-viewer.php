<?php

/**
 * Plugin Name: Slack Archive Viewer
 * Description: Display the contents of a JSON-formatted Slack archive.
 * Version: 0.3
 * Author: Mark Thompson
 * Update URI: https://github.com/hackhitchin/hh-wp-slack-viewer
 */

namespace HitchinHackspace\SlackViewer;

require_once __DIR__ . '/transient-cache.php';
require_once __DIR__ . '/model.php';

use Throwable, Exception;

// Couple of PHP8 utility backports

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
         $path = array_values(array_filter(explode('/', get_query_var('path') ?: '')));
         
         // Is it a request for the channel list?
         if (!$path) {
            $content = render_template('channel-list.php', ['archive' => $archive]);
         }
         else {
            $channel = $path[0];

            // Does the channel exist?
            $channel = $archive->getChannel($channel);

            if (!$channel)
               throw new Exception('No such channel.');

            $page = 0;
            
            if (count($path) > 1)
               $page = intval($path[1]) ?: 0;
            
            $content = render_template('channel-content.php', ['archive' => $archive, 'channel' => $channel, 'page' => $page]);
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
      $archive->getCache()->persist();

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