<?php

namespace HitchinHackspace\SlackViewer;

class ChannelRenderer {
    public $channel;
    public $page, $pageSize;
    public $messageCount;

    function __construct($channel, $page, $pageSize) {
        $this->channel = $channel;
        $this->page = $page ?: 1;
        $this->pageSize = $pageSize;

        $this->messageCount = $this->channel->getMessageCount();
    }

    function getMessages() {
        return $this->channel->getContent($this->getFirstIndex(), $this->pageSize);
    }

    function getFirstIndex() { return ($this->page - 1) * $this->pageSize; }
    function getLastIndex() { return min($this->getFirstIndex() + $this->pageSize, $this->messageCount); }
    function getPageCount() { return ceil($this->messageCount / $this->pageSize); }
    function hasPrevPage() { return $this->page > 1; }
    function hasNextPage() { return $this->page < $this->getPageCount(); }

    function getPrevPage() { return $this->hasPrevPage() ? $this->page - 1 : null; }
    function getNextPage() { return $this->hasNextPage() ? $this->page + 1 : null; }
        
    function getPageLink($page) { return "?path={$this->channel->getName()}/$page"; }

    /*
    function getPageName($page) {
        if ($page == 1) 
            return 'First';
        if ($page == $this->getPageCount())
            return 'Last';
        if ($page == $this->page - 1)
            return 'Previous';
        if ($page == $this->page + 1)
            return 'Next';

        return $page;
    }
    */

    function renderPageLink($page, $name) {
        $class = ($page == $this->page) ? ' class="current"' : '';

        $timestamp = $this->channel->getApproximateTimestamp(($page - 1) * $this->pageSize);

        ?>
            <li title="<?= date('d/m/Y', $timestamp) ?>"<?= $class ?>><a href="<?= $this->getPageLink($page) ?>"<?= $class ?>><?= $name ?></a></li>
        <?php
        return true;
    }

    function renderNav() {
        // Which pages should we link to?

        // How often should we add page links? It depends how many pages there are in total.
        $skip = intval($this->getPageCount() / 10);

        // If it's more than five, make sure it's a multiple of five.
        if ($skip > 5)
            $skip -= ($skip % 5);
        
        // Make sure it's not zero.
        if ($skip < 1)
            $skip = 1;

        $pages = array_merge(
            // An overview of the entire range
            range($this->page, -$skip, -$skip),
            range($this->page, $this->getPageCount() + $skip, $skip),
            // ... plus five pages back and forward from our current location.
            range($this->page - 3, $this->page + 3),
            // ... and the first and last page.
            [1, $this->getPageCount()]
        );

        // Remove those that are out of range.
        $pages = array_filter($pages, function ($page) { return $page > 1 && $page < $this->getPageCount(); });

        // ... and duplicates
        $pages = array_values(array_unique($pages));

        // ... then make sure they're in order.
        sort($pages, SORT_NUMERIC);

        ?>
            <nav class="pagenav">
                <ul>
                    <?php 
                        if ($this->getPageCount() > 1)
                            $this->renderPageLink(1, 'First');
                        if ($this->page > 1)
                            $this->renderPageLink($this->page - 1, 'Prev');

                        foreach ($pages as $page)
                            $this->renderPageLink($page, $page);

                        if ($this->page < $this->getPageCount())
                            $this->renderPageLink($this->page + 1, 'Next');
                        if ($this->getPageCount() > 1)
                            $this->renderPageLink($this->getPageCount(), 'Last');
                    ?>
                </ul>
            </nav>
        <?php
    }

    function getFilePreview($file, $atleast = null) {
        static $thumbKeys = [
            '1024' => 'thumb_1024',
            '960' => 'thumb_960',
            '800' => 'thumb_800',
            '720' => 'thumb_720',
            '480' => 'thumb_480',
            '360' => 'thumb_360',
            '160' => 'thumb_160',
            '80' => 'thumb_80',
            '64' => 'thumb_64'
        ];

        $best = null;

        foreach ($thumbKeys as $size => $key) {
            $url = $file[$key] ?? null;
            if (!$url)
                continue;

            if ($atleast === null)
                return $url;

            if ($size < $atleast)
                return $best;

            $best = $url;
        }

        return null;
    }

    function renderMessage($message) {
        $files = $message['files'] ?: [];
        ?>
        <div class="message">
            <?= $message['text']; ?>
            <?php if ($files) { ?>
                <ul class="files">
                    <?php foreach ($files as $file) { 
                        if ($file['mode'] == 'hidden_by_limit') {
                            ?>
                                <li class="hidden_by_limit">
                                    Due to Slack limits, this attachment can't be shown.
                                </li>
                            <?php
                        }
                        else {
                            ?>
                                <li>
                                    <a target="_blank" href="<?= $file['url_private'] ?>">
                                        <?php
                                            $preview = $this->getFilePreview($file, 160);
                                            if ($preview) {
                                                ?><img class="file-preview" src="<?= $preview ?>"><?php
                                            }
                                            else {
                                                ?><?= $file['name'] ?><?php
                                            }
                                        ?>
                                    </a>
                                </li>
                            <?php
                        }
                    }
                    ?>
                </ul>
            <?php } ?>
            </div>
        <?php
    }
}

$renderer = new ChannelRenderer($channel, $page, 100);

?>

<h2><a href="?path=">Slack Archives</a> > #<?= htmlspecialchars($renderer->channel->getName()) ?></h2>
<h3>Messages <?= $renderer->getFirstIndex() + 1 ?> to <?= $renderer->getLastIndex() ?> of <?= $renderer->messageCount ?></h3>

<?php $renderer->renderNav(); ?>

<ul class="channel-messages">
    <?php 
        foreach ($renderer->getMessages() as $message) { 
            $message = $message->getData();

            $user = $message['user'] ?? null;
            if ($user)
                $user = $archive->getUser($user);
            
            $avatarURL = null;
            if ($user)
                $avatarURL = $user->getAvatarURL(48);
    ?>
        <li class="slack-message">
            <div class="avatar">
                <?php if ($avatarURL) { ?><img class="avatar" src="<?= $avatarURL ?>"><?php } ?>
            </div>
            <div class="data">
                <?php $renderer->renderMessage($message); ?>
                <div class="meta">
                    <span class="user-display-name"><?= $user ? $user->getDisplayName() : 'Unknown' ?></span>
                    @
                    <span class="time"><?= date('Y-m-d H:i:s', $message['ts']) ?></span>
                </div>
            </div>
        </li>
    <?php } ?>
</ul>

<?php $renderer->renderNav(); ?>