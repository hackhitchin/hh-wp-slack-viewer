<?php

namespace HitchinHackspace\SlackViewer;

$content = $channel->getContent();

$content = array_values(array_filter($content, function ($message) { return ($message['ts'] ?? 0) != 0; }));

usort($content, function($a, $b) {
    return $a['ts'] - $b['ts'];
});
?>

<h2><a href="?path=">Slack Archives</a> > #<?= htmlspecialchars($channel->getName()) ?></h2>
<ul class="channel-messages">
    <?php 
        foreach ($content as $message) { 
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