<?php

$dir = __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR;
include($dir . 'ModifyMautic.php');

// We're going to make some assumptions about files existing here. Just FYI.
$content = file_get_contents($dir . 'template.index.html');

$modifyMautic = new ModifyMautic();

// Ya'll best have PHP7.
$action = $_GET['action'] ?? '';

// Do whatever the action dictates
switch ($action) {
    // Load editing template if the specified email can be found
    case 'edit':
        if (!empty($_GET['id'])) {
            $email = $modifyMautic->getEmail($_GET['id']);
            if (!empty($email)) {
                $template = file_get_contents($dir . 'template.edit.html');
                $content  = str_replace('{{content}}', $modifyMautic->replaceContent($email, $template), $content);
            } else {
                throw new \Exception('Failed to load email.');
            }
        } else {
            throw new \Exception('No email specified.');
        }
        break;

    // Save the submitted email deets
    case 'save':
        if (!empty($_POST['id'])) {
            $email = $modifyMautic->createEmailObject($_POST);
            print_R($email);
            if ($modifyMautic->updateEmail($_POST['id'], $email)) {
                $content = str_replace('{{content}}', 'Email saved. <a href="javascript:window.history.go(-2);">Back</a>', $content);
            } else {
                throw new \Exception('Email could not be saved.');
            }
        } else {
            throw new \Exception('No email specified.');
        }
    // Default to a list of all emails
    default:
        $emails        = $modifyMautic->getEmails();
        $emailTemplate = file_get_contents($dir . 'template.emails.html');

        $emailContent = '';
        foreach ($emails as $email) {
            $emailContent .= $modifyMautic->replaceContent($email, $emailTemplate) . "\n";
        }
        $content = str_replace('{{content}}', $emailContent, $content);
}

// Cleanup incase it was skipped for some reason
$content = str_replace('{{content}}', '', $content);

// As a predominantly API/SaaS/backend developer, echoing gives me the jitters. THAT'S WHAT TEMPLATE ENGINES ARE FOR.
echo $content;

