<?php
namespace MediaWiki\Extension\Shortcuts;

use Article;
use MediaWiki\MediaWikiServices;

class Hooks {
    public static function onArticleViewHeader( Article $article, &$outputDone, &$useParserCache ) {
        $context = $article->getContext();
        $request = $context->getRequest();
        $out = $context->getOutput();

        if ( $request->getVal( 'action' ) === 'render' ) {
            return true;
        }

        $services = MediaWikiServices::getInstance();
        $loadBalancer = $services->getDBLoadBalancer();
        if ( method_exists( $loadBalancer, 'getReadConnectionRef' ) ) {
            // MediaWiki 1.39+
            $dbr = $loadBalancer->getReadConnectionRef();
        } else {
            // MediaWiki < 1.39
            $dbr = $loadBalancer->getConnectionRef( DB_REPLICA );
        }
        
        $title = $article->getTitle();
        $ns = $title->getNamespace();
        $dbkey = $title->getDBkey();
        $shortcut = null;

        // Проверяем, состоит ли название только из ASCII (и не слишком ли длинное)
        $isAscii = !preg_match( '/[^a-zA-Z0-9_-]/s', $dbkey );
        $isShort = $isAscii && strlen( $dbkey ) <= 32;

        if ( !$isShort || $ns !== NS_MAIN ) {
            // Поддержка REGEXP для MySQL/MariaDB и ~ для PostgreSQL
            $regexOp = $dbr->getType() === 'mysql' ? 'REGEXP' : '~';
            
            $where = [
                'rd_namespace' => $ns,
                'rd_title' => $dbkey,
                'page_id=rd_from',
                "page_title {$regexOp} '^[a-zA-Z0-9_-]+$'"
            ];
            
            if ( $isAscii ) {
                $where[] = 'LENGTH(page_title) < ' . mb_strlen( $dbkey );
            }

            $row = $dbr->selectRow(
                [ 'redirect', 'page' ],
                '*',
                $where,
                __METHOD__,
                [ 'ORDER BY' => 'LENGTH(page_title) ASC' ]
            );

            if ( $row && ( strlen( $row->page_title ) <= 32 || $row->page_namespace == NS_MAIN ) ) {
                $shortcut = $services->getTitleFactory()->newFromRow( $row );
            }
        }

        $contLang = $services->getContentLanguage();
        $nsInfo = $services->getNamespaceInfo();
        $canonicalName = $nsInfo->getCanonicalName( $ns );
        $nsText = $contLang->getNsText( $ns );

        // Меняем имя пространства имен на английское в короткой ссылке (как было в оригинале)
        if ( $isShort && !$shortcut && $ns !== NS_MAIN && $canonicalName && $nsText !== $canonicalName ) {
            $shortcut = $title;
        }

        if ( $shortcut ) {
            $permManager = $services->getPermissionManager();
            $user = $context->getUser();

            if ( $permManager->userCan( 'read', $user, $shortcut ) ) {
                $out->addModuleStyles( [ 'ext.Shortcuts' ] );
                
                $shortcutDbKey = $shortcut->getDBkey();
                $shortcutText = $shortcut->getText();
                $shortcutNs = $shortcut->getNamespace();

                if ( $shortcutNs !== NS_MAIN ) {
                    $canonicalShortcutNsName = $nsInfo->getCanonicalName( $shortcutNs );
                    $prefix = $canonicalShortcutNsName ?: $contLang->getNsText( $shortcutNs );
                    
                    if ( !$shortcutDbKey ) {
                        $prefix = $contLang->getNsText( $shortcutNs );
                    }
                    
                    $shortcutDbKey = $prefix . ':' . $shortcutDbKey;
                    $shortcutText = $prefix . ':' . $shortcutText;
                }

                $articlePath = $services->getMainConfig()->get( 'ArticlePath' );
                $href = str_replace( '$1', wfUrlencode( $shortcutDbKey ), $articlePath );

                $link = '<a href="' . htmlspecialchars( $href ) . '">' . htmlspecialchars( $shortcutText ) . '</a>';
                $msg = $context->msg( 'shortcut-link' )->rawParams( $link )->text();
                
                // Выводим HTML. Добавлен clear:both для безопасного обтекания элементов
                $out->addHTML( '<div style="clear:both;height:1px"></div><div class="shortcut-link">' . $msg . '</div>' );
            }
        }

        return true;
    }
}
