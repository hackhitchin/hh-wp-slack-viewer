<?php

namespace HitchinHackspace\SlackViewer;

/**
 * @var BaseRenderer $renderer
 */
?>

<h3>Messages <?= $renderer->getFirstIndex() + 1 ?> to <?= $renderer->getLastIndex() ?> of <?= $renderer->getMessageCount() ?></h3>

<?php $renderer->renderNav(); ?>

<ul class="channel-messages">
    <?php 
        foreach ($renderer->getMessages() as $message) { 
    ?>
        <li id="<?= $message->getTimestamp() ?>" class="slack-message">
            <?php $renderer->render($message); ?>
        </li>
    <?php } ?>
</ul>

<?php $renderer->renderNav(); ?>