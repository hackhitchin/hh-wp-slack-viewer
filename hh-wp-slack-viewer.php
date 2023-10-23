<?php

/**
 * Plugin Name: Slack Archive Viewer
 * Description: Display the contents of a JSON-formatted Slack archive.
 * Version: 0.5
 * Author: Mark Thompson
 * Update URI: https://github.com/hackhitchin/hh-wp-slack-viewer
 */

namespace HitchinHackspace\SlackViewer;

require_once __DIR__ . '/transient-cache.php';
require_once __DIR__ . '/model.php';
require_once __DIR__ . '/channel-renderer.php';

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

function getBestImageURL($urls, $atleast = null) {
   $best = null;

   foreach ($urls as $size => $url) {
      if ($atleast === null)
         return $url;

      if ($size < $atleast)
         return $best;

      $best = $url;
   }

   return null;
}

class SearchResultMessage extends SlackMessage {
   protected $channel;
   protected $offset;

   public function __construct($message, $channel, $offset) {
      parent::__construct($message->getData());
      $this->channel = $channel;
      $this->offset = $offset;
   }

   public function getChannel() { return $this->channel; }
   public function getOffset() { return $this->offset; }
}

class SearchResults implements MessageSequence {
   private $archive;
   private $searchTerm;
   private $messages;

   public function __construct($archive, $searchTerm) {
      $this->archive = $archive;
      $this->searchTerm = $searchTerm;
      $this->messages = null;
   }

   public function getArchive() { return $this->archive; }
   public function getCount() { return count($this->getResults()); }
   public function getSearchTerm() { return $this->searchTerm; }

   public function getContent($offset = 0, $limit = null) { 
      return array_slice($this->getResults(), $offset, $limit);
   }

   public function getApproximateTimestamp($offset) { 
      $message = $this->getResults()[$offset] ?? null;
      return $message ? $message->getTimestamp() : 0;
   }

   private function performSearch() {
      foreach ($this->archive->getChannelList() as $channel)
         foreach ($channel->getContent() as $index => $message)
            if ($message->matches($this->searchTerm))
               yield new SearchResultMessage($message, $channel, $index);
   }

   private function getResults() {
      if ($this->messages === null) {
         $this->messages = iterator_to_array($this->performSearch());

         usort($this->messages, function ($a, $b) {
            return $a->getTimestamp() <=> $b->getTimestamp();
         });
      }
      
      return $this->messages;
   }
}

// Render the contents of the slack archive, based on the path the user has selected (in the query)
add_shortcode('hh_slack_archives', function($attrs) {
   add_filter('jetpack_photon_skip_image', function() { return true; });

   ob_start();

   $title = 'Error';

   try {
      try {
         // Load the Slack archive
         $archive = get_slack_archive();

         // Work out which page the user wants.
         $path = get_query_var('path');
         if (!is_array($path))
            $path = [$path];

         // Join all path components, even if they were in separate query variables
         $path = array_merge(...array_map(fn ($value) => explode('/', $value), $path));

         // Filter any blank ones.
         $path = array_values(array_filter($path));

         $channel = array_shift($path);

         // Is it a request for the channel list?
         if (!$channel) {
            $content = render_template('channel-list.php', ['archive' => $archive]);
            $title = 'All Channels';
         }
         else {
            $page = null;
            $pageSize = 100;

            // Is it a search?
            if ($channel == 'search') {
               $term = array_shift($path);
               
               if ($term) {
                  $messages = new SearchResults($archive, $term);

                  $renderer = new SearchRenderer($messages);

                  $goto = array_shift($path);
                  if (is_numeric($goto))
                     $page = intval($goto);
               }
            }
            else {
               // Does the channel exist?
               $messages = $archive->getChannelByName($channel);

               if (!$messages)
                  throw new Exception('No such channel.');

               $renderer = new ChannelRenderer($messages);

               $goto = array_shift($path);
               if ($goto == 'message') {
                  $messageIndex = array_shift($path);
                  if (is_numeric($messageIndex)) {
                     $page = floor($messageIndex / $pageSize) + 1;
                  }
               }
               else if (is_numeric($goto))
                  $page = intval($goto);
            }

            $renderer->setPagination($page, $pageSize);

            $title = $renderer->getTitle();
            $content = render_template('channel-content.php', ['renderer' => $renderer]);
         }
      }
      catch (Throwable $e) {
         error_log(print_r($e, true));
         $content = $e->getMessage();
      }

      ?>
         <section class="slack-archives">
            <header>
               <h2><a href="?path=">Slack Archives</a> > <?= $title ?></h2>
               <form class="slack-search">
                  <input type="hidden" name="path[]" value="search">
                  <input type="text" name="path[]" aira-label="Search Term">
                  <button aria-label="Submit"><svg xmlns="http://www.w3.org/2000/svg" height="1em" viewBox="0 0 512 512"><!--! Font Awesome Free 6.4.2 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license (Commercial License) Copyright 2023 Fonticons, Inc. --><path d="M416 208c0 45.9-14.9 88.3-40 122.7L502.6 457.4c12.5 12.5 12.5 32.8 0 45.3s-32.8 12.5-45.3 0L330.7 376c-34.4 25.2-76.8 40-122.7 40C93.1 416 0 322.9 0 208S93.1 0 208 0S416 93.1 416 208zM208 352a144 144 0 1 0 0-288 144 144 0 1 0 0 288z" fill="currentColor" /></svg></button>
               </form>
            </form>
            </header>
            <?= $content ?>
         </section>
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