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
    global $wgOut, $wgContLang, $wgArticlePath, $wgCanonicalNamespaceNames;
    $dbr = wfGetDB(DB_SLAVE);
    $t = $article->getTitle();
    $shortcut = NULL;
    $ns = $t->getNamespace();
    $dbkey = $t->getDBkey();
    // Do not output "shortcut" links to articles which already have "short" title
    $is_ascii = !preg_match('/[^a-zA-Z0-9_-]/s', $dbkey);
    $is_short = $is_ascii && strlen($dbkey) <= 32;
    if (!$is_short || $ns != NS_MAIN)
    {
        $where = array(
            'rd_namespace' => $ns,
            'rd_title' => $dbkey,
            'page_id=rd_from',
            'page_title REGEXP \'^[a-zA-Z0-9_-]+$\'',
        );
        if ($is_ascii)
            $where[] = 'LENGTH(page_title) < '.mb_strlen($dbkey);
        $res = $dbr->select(array('redirect', 'page'), '*', $where,
            __METHOD__, array('ORDER BY' => 'LENGTH(page_title) ASC', 'LIMIT' => 1));
        $row = $res->fetchObject();
        // Check if shortcut is really a shortcut
        if ($row && (strlen($row->page_title) <= 32 || $row->page_namespace == NS_MAIN))
            $shortcut = Title::newFromRow($row);
    }
    // Change namespace name to English one in the short link
    if ($is_short && !$shortcut && $ns != NS_MAIN &&
        !empty($wgCanonicalNamespaceNames[$ns]) &&
        $wgContLang->getNsText($ns) != $wgCanonicalNamespaceNames[$ns])
        $shortcut = $t;
    if ($shortcut && $shortcut->userCanRead())
    {
        // Don't use $wgUser->getSkin()->link() as there is no way
        // to force it output links with canonical namespace names
        $dbkey = $shortcut->getDBkey();
        $text = $shortcut->getText();
        $ns = $shortcut->getNamespace();
        if ($ns != NS_MAIN)
        {
            $nstext = $wgCanonicalNamespaceNames[$ns];
            if (!$dbkey)
                $nstext = $wgContLang->getNsText($ns);
            $dbkey = "$nstext:$dbkey";
            $text = "$nstext:$text";
        }
        $wgOut->addHTML(wfMsgNoTrans('shortcut-link',
            '<a href="'.htmlspecialchars(str_replace('$1', $dbkey, $wgArticlePath)).
            '">'.htmlspecialchars($text).'</a>'
        ));
    }
    return true;
}
