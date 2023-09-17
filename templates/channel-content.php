<?php

namespace HitchinHackspace\SlackViewer;

$messageCount = $channel->getMessageCount();

$pageSize = 100;

$first = $page * $pageSize;
$last = min($first + $pageSize, $messageCount);

$messages = $channel->getContent($page * $pageSize, $pageSize);

$pageLink = function($page) use ($channel) {
    if ($page === null)
        return null;

    return "?path={$channel->getName()}/$page";
};

$renderNav = function() use ($channel, $first, $last, $pageSize, $pageLink) {
    $prevPage = ($first > 0) ? ($first - $pageSize) / $pageSize : null;
    $nextPage = ($first + $pageSize < $channel->getMessageCount()) ? ($first + $pageSize) / $pageSize : null;

    $prevPageLink = $pageLink($prevPage);
    $nextPageLink = $pageLink($nextPage);

    ?>
    <nav class="pagenav">
        <?php if ($prevPage !== null) { ?><a href="<?= $prevPageLink ?>">Previous</a><?php } ?>
        <?php if ($nextPage !== null) { ?><a href="<?= $nextPageLink ?>">Next</a><?php } ?>
    </nav>
    <?php
}

?>

<h2><a href="?path=">Slack Archives</a> > #<?= htmlspecialchars($channel->getName()) ?></h2>
<h3>Messages <?= $first + 1 ?> to <?= $last ?> of <?= $messageCount ?></h3>

<?php $renderNav(); ?>

<ul class="channel-messages">
    <?php 
        foreach ($messages as $message) { 
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

<?php $renderNav(); ?>