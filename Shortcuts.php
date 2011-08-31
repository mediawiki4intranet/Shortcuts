<?php

/**
 * MediaWiki Shortcuts extension
 *
 * @author Vitaliy Filippov <vitalif@mail.ru>
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 *
 * For each page, this extension tries to find a minimal length alias
 * consisting only of latin letters and output it to the page header.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 */

if (!defined('MEDIAWIKI'))
    die();

$wgExtensionFunctions[] = 'wfShortcuts';
$wgExtensionMessagesFiles['Shortcuts'] = dirname(__FILE__) . '/Shortcuts.i18n.php';
$wgExtensionCredits['other'][] = array (
    'name'        => 'Display shortcut links to pages',
    'description' => 'On each page, a shortcut redirect link of minimal length will be displayed if it exists.',
    'author'      => 'Vitaliy Filippov',
    'url'         => 'http://wiki.4intra.net/Shortcuts_(MediaWiki)',
    'version'     => '2011-08-29',
);

function wfShortcuts()
{
    global $wgHooks;
    wfLoadExtensionMessages('Shortcuts');
    $wgHooks['ArticleViewHeader'][] = 'efShortcutsArticleViewHeader';
}

function efShortcutsArticleViewHeader($article, &$outputDone, &$useParserCache)
{
    global $wgOut, $wgUser;
    $dbr = wfGetDB(DB_SLAVE);
    $t = $article->getTitle();
    // Do not output "shortcut" links to articles which already have "short" title
    if (preg_match('/[^a-zA-Z0-9_-]/s', $t->getDBkey()) || strlen($t->getDBkey()) > 32)
    {
        $res = $dbr->select(array('redirect', 'page'), '*', array(
            'rd_namespace' => $t->getNamespace(),
            'rd_title' => $t->getDBkey(),
            'page_id=rd_from',
            'page_title REGEXP \'^[a-zA-Z0-9_-]+$\'',
        ), __METHOD__, array('ORDER BY' => 'LENGTH(rd_title) DESC', 'LIMIT' => 1));
        $row = $res->fetchObject();
        if ($row)
        {
            $title = Title::newFromRow($row);
            if ($title->userCanRead())
                $wgOut->addHTML(wfMsgNoTrans('shortcut-link', $wgUser->getSkin()->link($title)));
        }
    }
    return true;
}
