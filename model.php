<?php

namespace HitchinHackspace\SlackViewer;

use ZipArchive;
use Exception;

// Represents a single slack export (currently represented by one zip file)
class SlackArchive {
    use HasCache;
 
    // A ZipArchive instance containing the export.
    private $archive;
 
    // Slack user cache.
    private $users = null;
 
    // The transient cache
    private $cache;
 
    // Construct an archive, given an ID of an item in the media library.
    function __construct($archive_id) {
       $this->cache = new TransientCache("slackcache-$archive_id");
 
       $this->archive = new ZipArchive();
       if ($this->archive->open(get_attached_file($archive_id)) !== true)
          throw new Exception('There was a problem opening the Slack archive file.');
    }
 
    function getCache() { return $this->cache; }
 
    // Get a list of the files contained within this archive.
    function getFileList() {
       return $this->cached('file-list', function() {
          $files = [];
          for ($i = 0; $i < count($this->archive); ++$i)
             $files[] = $this->archive->getNameIndex($i);
 
          return $files;
       });
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
       foreach ($channels as $channelObject) 
          yield new SlackChannel($this, $channelObject);
    }
 
    // Get the set of 'archived' channels.
    function getArchivedChannels() {
       foreach ($this->getChannelList() as $channel)
          if ($channel->isArchived())
             yield $channel;
    }
 
    // Get the set of (non-archived) 'general' channels.
    function getGeneralChannels() {
       foreach ($this->getChannelList() as $channel)
          if (!$channel->isArchived() && $channel->isGeneral())
             yield $channel;
    }
 
    // Get the set of non-archived, non-general channels.
    function getStandardChannels() {
       foreach ($this->getChannelList() as $channel)
          if (!$channel->isArchived() && !$channel->isGeneral())
             yield $channel;
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
    public $archive;
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
    use HasCache;
    
    // A reference to the containing SlackArchive.
    public $archive;
    // The backing JSON object.
    public $obj;
    // The set of files containing content
    private $files = null;
    
    function __construct($archive, $obj) {
       $this->archive = $archive;
       $this->obj = $obj;
    }
 
    private function getArchiveChannelCache() { 
       return $this->archive->getSubCache('channels');
    }
 
    public function getCache() {
       return new SubCache($this->getArchiveChannelCache(), $this->getID());
    }
 
    function getID() { return $this->obj['id']; }
    function getName() { return $this->obj['name']; }
    function getTopic() { return $this->obj['topic']['value']; }
    function getPurpose() { return $this->obj['purpose']['value']; }
    function isArchived() { return $this->obj['is_archived']; }
    function isGeneral() { return $this->obj['is_general']; }
 
    function getFilesInner() {
       $prefix = "{$this->getName()}/";
 
       $files = [];
 
       foreach ($this->archive->getFileList() as $file) {
          if (!starts_with($file, $prefix))
             continue;
 
          $file = SlackChannelFile::create($this, $file);
          if ($file) 
             $files[] = $file;
       }
 
       usort($files, function ($a, $b) { return $a->getFirstMessageTimestamp() <=> $b->getFirstMessageTimestamp(); });
 
       return $files;
    }
 
    // Get a list of all the files in the archive relating to the message content of this channel.
    function getFiles() {
       if ($this->files === null)
          $this->files = $this->getFilesInner();
 
       return $this->files;
    }
 
    // Get the total number of messages in this channel.
    function getMessageCount() {
       $count = 0;
       foreach ($this->getFiles() as $file)
          $count += $file->getMessageCount();
 
       return $count;
    }
 
   // Guess at a rough date of a message, without parsing the full message file.
   function getApproximateTimestamp($offset) {
      foreach ($this->getFiles() as $file) {
         // Does this file contain messages within the range?
         $count = $file->getMessageCount();
         if ($count > $offset)
            return $file->getFirstMessageTimestamp();

         // No. Skip it.
         $offset -= $count;
      }

      return time();
   }

    // Get a subset of messages from this channel, based on a first message index and count.
    function getContent($offset = 0, $limit = null) {
       foreach ($this->getFiles() as $file) {
          // Are we waiting to start the range?
          if ($offset) {
             // Does this file contain messages within the range?
             $count = $file->getMessageCount();
             if ($count <= $offset) {
                // No. Skip it.
                $offset -= $count;
                continue;
             }
          }
          foreach ($file->getMessages() as $message) {
             // Are we still skipping some messages within this file?
             if ($offset) {
                $offset -= 1;
                continue;
             }
             yield $message;
             // Are we limiting how much to return?
             if ($limit !== null)
                if (--$limit == 0)
                   return; // We're done.
          }
       }
    }
 }
 
 // Represents a file containing (a subset of) messages from a Slack channel.
 class SlackChannelFile {
    use HasCache;
 
    // A reference to the containing SlackChannel.
    private $channel;
    // The file name within the archive.
    private $filename;
 
    function __construct($channel, $filename) {
       $this->channel = $channel;
       $this->filename = $filename;
    }
 
    private function getChannelFileCache() {
       return $this->channel->getSubCache('files');
    }
 
    private function getCache() {
       return new SubCache($this->getChannelFileCache(), $this->filename);
    }
 
    static function create($channel, $filename) {
       // Is it a JSON file?
       if (!ends_with($filename, '.json'))
          return null;
 
       // Does it look like it contains messages?
       $basename = basename($filename, '.json');
       if (date_create_from_format('Y-m-d', $basename) === false)
          return null;
 
       return new SlackChannelFile($channel, $filename);
    }
 
    function getMessageCount() {
       return $this->cached('message-count', function() {
          return iterator_count($this->getMessages());
       });
    }
 
    function getMessages() {
       foreach ($this->channel->archive->getJSON($this->filename) as $message)
          yield new SlackMessage($message);
    }
 
    function getFirstMessageTimestamp() {
       return $this->cached('first-message-timestamp', function() {
          return $this->getMessages()->current()->getTimestamp();
       });
    }
 }
 
 class SlackMessage {
    // The backing JSON object.
    private $obj;
 
    function __construct($obj) {
       $this->obj = $obj;
    }
 
    function getData() {
       return $this->obj;
    }
 
    function getTimestamp() {
       return $this->getData()['ts'];
    }
 }