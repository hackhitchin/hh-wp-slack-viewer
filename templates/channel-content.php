<?php

namespace HitchinHackspace\SlackViewer;

class ChannelRenderer {
    public $channel;
    public $page, $pageSize;
    public $first, $last;
    public $messageCount;

    function __construct($channel, $page, $pageSize) {
        $this->channel = $channel;
        $this->page = $page;
        $this->pageSize = $pageSize;

        $this->messageCount = $this->channel->getMessageCount();

        $this->first = $this->page * $this->pageSize;
        $this->last = min($this->first + $this->pageSize, $this->messageCount);
    }

    function getMessages() {
        return $this->channel->getContent($this->page * $this->pageSize, $this->pageSize);
    }
    
    function getPageLink($page) {
        if ($page === null)
            return null;

        return "?path={$this->channel->getName()}/$page";
    }

    function renderNav() {
        $prevPage = ($this->first > 0) ? ($this->first - $this->pageSize) / $this->pageSize : null;
        $nextPage = ($this->first + $this->pageSize < $this->messageCount) ? ($this->first + $this->pageSize) / $this->pageSize : null;

        $prevPageLink = $this->getPageLink($prevPage);
        $nextPageLink = $this->getPageLink($nextPage);

        ?>
        <nav class="pagenav">
            <?php if ($prevPage !== null) { ?><a href="<?= $prevPageLink ?>">Previous</a><?php } ?>
            <?php if ($nextPage !== null) { ?><a href="<?= $nextPageLink ?>">Next</a><?php } ?>
        </nav>
        <?php
    }
}

$renderer = new ChannelRenderer($channel, $page, 100);

?>

<h2><a href="?path=">Slack Archives</a> > #<?= htmlspecialchars($renderer->channel->getName()) ?></h2>
<h3>Messages <?= $renderer->first + 1 ?> to <?= $renderer->last ?> of <?= $renderer->messageCount ?></h3>

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
                <span class="message">
                    <?= $message['text']; ?>
                </span>
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