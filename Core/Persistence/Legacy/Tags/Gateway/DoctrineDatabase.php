<?php

namespace Netgen\TagsBundle\Core\Persistence\Legacy\Tags\Gateway;

use Netgen\TagsBundle\SPI\Persistence\Tags\Tag;
use Netgen\TagsBundle\SPI\Persistence\Tags\CreateStruct;
use Netgen\TagsBundle\SPI\Persistence\Tags\UpdateStruct;
use Netgen\TagsBundle\Core\Persistence\Legacy\Tags\Gateway;
use eZ\Publish\Core\Persistence\Database\DatabaseHandler;
use eZ\Publish\SPI\Persistence\Content\Language\Handler as LanguageHandler;
use eZ\Publish\Core\Persistence\Legacy\Content\Language\MaskGenerator as LanguageMaskGenerator;
use eZ\Publish\Core\Base\Exceptions\NotFoundException;
use PDO;

class DoctrineDatabase extends Gateway
{
    /**
     * Database handler
     *
     * @var \eZ\Publish\Core\Persistence\Database\DatabaseHandler
     */
    protected $handler;

    /**
     * Caching language handler
     *
     * @var \eZ\Publish\SPI\Persistence\Content\Language\Handler
     */
    protected $languageHandler;

    /**
     * Language mask generator
     *
     * @var \eZ\Publish\Core\Persistence\Legacy\Content\Language\MaskGenerator
     */
    protected $languageMaskGenerator;

    /**
     * Constructor
     *
     * @param \eZ\Publish\Core\Persistence\Database\DatabaseHandler $handler
     * @param \eZ\Publish\SPI\Persistence\Content\Language\Handler $languageHandler
     * @param \eZ\Publish\Core\Persistence\Legacy\Content\Language\MaskGenerator $languageMaskGenerator
     */
    public function __construct( DatabaseHandler $handler, LanguageHandler $languageHandler, LanguageMaskGenerator $languageMaskGenerator )
    {
        $this->handler = $handler;
        $this->languageHandler = $languageHandler;
        $this->languageMaskGenerator = $languageMaskGenerator;
    }

    /**
     * Returns an array with basic tag data
     *
     * @throws \eZ\Publish\Core\Base\Exceptions\NotFoundException
     *
     * @param mixed $tagId
     *
     * @return array
     */
    public function getBasicTagData( $tagId )
    {
        $query = $this->handler->createSelectQuery();
        $query
            ->select( "*" )
            ->from( $this->handler->quoteTable( "eztags" ) )
            ->where(
                $query->expr->eq(
                    $this->handler->quoteColumn( "id" ),
                    $query->bindValue( $tagId, null, PDO::PARAM_INT )
                )
            );

        $statement = $query->prepare();
        $statement->execute();

        if ( $row = $statement->fetch( PDO::FETCH_ASSOC ) )
        {
            return $row;
        }

        throw new NotFoundException( "tag", $tagId );
    }

    /**
     * Returns an array with full tag data
     *
     * @param mixed $tagId
     *
     * @return array
     */
    public function getFullTagData( $tagId )
    {
        $query = $this->createTagFindQuery();
        $query->where(
            $query->expr->eq(
                $this->handler->quoteColumn( 'id', 'eztags' ),
                $query->bindValue( $tagId, null, PDO::PARAM_INT )
            )
        );

        $statement = $query->prepare();
        $statement->execute();

        return $statement->fetchAll( PDO::FETCH_ASSOC );
    }

    /**
     * Returns an array with full tag data for the tag with $remoteId
     *
     * @param string $remoteId
     *
     * @return array
     */
    public function getFullTagDataByRemoteId( $remoteId )
    {
        $query = $this->createTagFindQuery();
        $query->where(
            $query->expr->eq(
                $this->handler->quoteColumn( 'remote_id', 'eztags' ),
                $query->bindValue( $remoteId, null, PDO::PARAM_INT )
            )
        );

        $statement = $query->prepare();
        $statement->execute();

        return $statement->fetchAll( PDO::FETCH_ASSOC );
    }

    /**
     * Returns an array with basic tag data for the tag with $url
     *
     * @throws \eZ\Publish\Core\Base\Exceptions\NotFoundException
     *
     * @param string $url
     *
     * @return array
     */
    public function getBasicTagDataByUrl( $url )
    {
        $explodedUrl = explode( '/', $url );
        $parentId = 0;

        foreach ( $explodedUrl as $urlItem )
        {
            $urlItem = trim( $urlItem );
            if ( empty( $urlItem ) )
            {
                continue;
            }

            $query = $this->handler->createSelectQuery();
            $query
                ->select( "*" )
                ->from( $this->handler->quoteTable( "eztags" ) )
                ->where(
                    $query->expr->lAnd(
                        $query->expr->eq(
                            $this->handler->quoteColumn( "parent_id" ),
                            $query->bindValue( $parentId, null, PDO::PARAM_INT )
                        ),
                        $query->expr->eq(
                            $this->handler->quoteColumn( "keyword" ),
                            $query->bindValue( urldecode( $urlItem ), null, PDO::PARAM_STR )
                        )
                    )
                );

            $statement = $query->prepare();
            $statement->execute();

            if ( $row = $statement->fetch( PDO::FETCH_ASSOC ) )
            {
                $parentId = (int)$row["id"];
                continue;
            }

            throw new NotFoundException( "tag", $url );
        }

        return $row;
    }

    /**
     * Returns data for the first level children of the tag identified by given $tagId
     *
     * @param mixed $tagId
     * @param int $offset The start offset for paging
     * @param int $limit The number of tags returned. If $limit = -1 all children starting at $offset are returned
     *
     * @return array
     */
    public function getChildren( $tagId, $offset = 0, $limit = -1 )
    {
        $query = $this->createTagFindQuery();
        $query->where(
            $query->expr->lAnd(
                $query->expr->eq(
                    $this->handler->quoteColumn( "parent_id", "eztags" ),
                    $query->bindValue( $tagId, null, PDO::PARAM_INT )
                ),
                $query->expr->eq( $this->handler->quoteColumn( "main_tag_id", "eztags" ), 0 )
            )
        )
        ->limit( $limit > 0 ? $limit : PHP_INT_MAX, $offset );

        $statement = $query->prepare();
        $statement->execute();

        return $statement->fetchAll( PDO::FETCH_ASSOC );
    }

    /**
     * Returns how many tags exist below tag identified by $tagId
     *
     * @param mixed $tagId
     *
     * @return int
     */
    public function getChildrenCount( $tagId )
    {
        $query = $this->handler->createSelectQuery();
        $query
            ->select(
                $query->alias( $query->expr->count( "*" ), "count" )
            )
            ->from( $this->handler->quoteTable( "eztags" ) )
            ->where(
                $query->expr->lAnd(
                    $query->expr->eq(
                        $this->handler->quoteColumn( "parent_id", "eztags" ),
                        $query->bindValue( $tagId, null, PDO::PARAM_INT )
                    ),
                    $query->expr->eq( $this->handler->quoteColumn( "main_tag_id", "eztags" ), 0 )
                )
            );

        $statement = $query->prepare();
        $statement->execute();

        $rows = $statement->fetchAll( PDO::FETCH_ASSOC );

        return (int)$rows[0]["count"];
    }

    /**
     * Returns data for tags identified by given $keyword
     *
     * @param string $keyword
     * @param int $offset The start offset for paging
     * @param int $limit The number of tags returned. If $limit = -1 all tags starting at $offset are returned
     *
     * @return array
     */
    public function getTagsByKeyword( $keyword, $offset = 0, $limit = -1 )
    {
        $query = $this->handler->createSelectQuery();
        $query
            ->select( "*" )
            ->from( $this->handler->quoteTable( "eztags" ) )
            ->where(
                $query->expr->eq(
                    $this->handler->quoteColumn( "keyword", "eztags" ),
                    $query->bindValue( $keyword, null, PDO::PARAM_STR )
                )
            )
            ->limit( $limit > 0 ? $limit : PHP_INT_MAX, $offset );

        $statement = $query->prepare();
        $statement->execute();

        return $statement->fetchAll( PDO::FETCH_ASSOC );
    }

    /**
     * Returns how many tags exist with $keyword
     *
     * @param string $keyword
     *
     * @return int
     */
    public function getTagsByKeywordCount( $keyword )
    {
        $query = $this->handler->createSelectQuery();
        $query
            ->select(
                $query->alias( $query->expr->count( "*" ), "count" )
            )
            ->from( $this->handler->quoteTable( "eztags" ) )
            ->where(
                $query->expr->eq(
                    $this->handler->quoteColumn( "keyword", "eztags" ),
                    $query->bindValue( $keyword, null, PDO::PARAM_STR )
                )
            );

        $statement = $query->prepare();
        $statement->execute();

        $rows = $statement->fetchAll( PDO::FETCH_ASSOC );

        return (int)$rows[0]["count"];
    }

    /**
     * Returns data for synonyms of the tag identified by given $tagId
     *
     * @param mixed $tagId
     * @param int $offset The start offset for paging
     * @param int $limit The number of tags returned. If $limit = -1 all synonyms starting at $offset are returned
     *
     * @return array
     */
    public function getSynonyms( $tagId, $offset = 0, $limit = -1 )
    {
        $query = $this->createTagFindQuery();
        $query->where(
            $query->expr->eq(
                $this->handler->quoteColumn( "main_tag_id", "eztags" ),
                $query->bindValue( $tagId, null, PDO::PARAM_INT )
            )
        )
        ->limit( $limit > 0 ? $limit : PHP_INT_MAX, $offset );

        $statement = $query->prepare();
        $statement->execute();

        return $statement->fetchAll( PDO::FETCH_ASSOC );
    }

    /**
     * Returns how many synonyms exist for a tag identified by $tagId
     *
     * @param mixed $tagId
     *
     * @return int
     */
    public function getSynonymCount( $tagId )
    {
        $query = $this->handler->createSelectQuery();
        $query
            ->select(
                $query->alias( $query->expr->count( "*" ), "count" )
            )
            ->from( $this->handler->quoteTable( "eztags" ) )
            ->where(
                $query->expr->eq(
                    $this->handler->quoteColumn( "main_tag_id", "eztags" ),
                    $query->bindValue( $tagId, null, PDO::PARAM_INT )
                )
            );

        $statement = $query->prepare();
        $statement->execute();

        $rows = $statement->fetchAll( PDO::FETCH_ASSOC );

        return (int)$rows[0]["count"];
    }

    /**
     * Loads content IDs related to tag identified by $tagId
     *
     * @param mixed $tagId
     * @param int $offset The start offset for paging
     * @param int $limit The number of content IDs returned. If $limit = -1 all content IDs starting at $offset are returned
     *
     * @return array
     */
    function getRelatedContentIds( $tagId, $offset = 0, $limit = -1 )
    {
        $query = $this->handler->createSelectQuery();
        $query
            ->selectDistinct(
                $this->handler->quoteColumn( "object_id", "eztags_attribute_link" )
            )
            ->from( $this->handler->quoteTable( "eztags_attribute_link" ) )
            ->innerJoin(
                $this->handler->quoteTable( "ezcontentobject" ),
                $query->expr->lAnd(
                    $query->expr->eq(
                        $this->handler->quoteColumn( "object_id", "eztags_attribute_link" ),
                        $this->handler->quoteColumn( "id", "ezcontentobject" )
                    ),
                    $query->expr->eq(
                        $this->handler->quoteColumn( "objectattribute_version", "eztags_attribute_link" ),
                        $this->handler->quoteColumn( "current_version", "ezcontentobject" )
                    ),
                    $query->expr->eq(
                        $this->handler->quoteColumn( "status", "ezcontentobject" ),
                        1
                    )
                )
            )->where(
                $query->expr->eq(
                    $this->handler->quoteColumn( "keyword_id", "eztags_attribute_link" ),
                    $query->bindValue( $tagId, null, PDO::PARAM_INT )
                )
            )->limit( $limit > 0 ? $limit : PHP_INT_MAX, $offset );

        $statement = $query->prepare();
        $statement->execute();

        $rows = $statement->fetchAll( PDO::FETCH_ASSOC );

        $contentIds = array();
        foreach ( $rows as $row )
        {
            $contentIds[] = (int)$row["object_id"];
        }

        return $contentIds;
    }

    /**
     * Returns the number of content objects related to tag identified by $tagId
     *
     * @param mixed $tagId
     *
     * @return int
     */
    function getRelatedContentCount( $tagId )
    {
        $query = $this->handler->createSelectQuery();
        $query
            ->selectDistinct(
                $query->alias(
                    $query->expr->count(
                        $this->handler->quoteColumn( "object_id", "eztags_attribute_link" )
                    ),
                    "count"
                )
            )
            ->from( $this->handler->quoteTable( "eztags_attribute_link" ) )
            ->innerJoin(
                $this->handler->quoteTable( "ezcontentobject" ),
                $query->expr->lAnd(
                    $query->expr->eq(
                        $this->handler->quoteColumn( "object_id", "eztags_attribute_link" ),
                        $this->handler->quoteColumn( "id", "ezcontentobject" )
                    ),
                    $query->expr->eq(
                        $this->handler->quoteColumn( "objectattribute_version", "eztags_attribute_link" ),
                        $this->handler->quoteColumn( "current_version", "ezcontentobject" )
                    ),
                    $query->expr->eq(
                        $this->handler->quoteColumn( "status", "ezcontentobject" ),
                        1
                    )
                )
            )->where(
                $query->expr->eq(
                    $this->handler->quoteColumn( "keyword_id", "eztags_attribute_link" ),
                    $query->bindValue( $tagId, null, PDO::PARAM_INT )
                )
            );

        $statement = $query->prepare();
        $statement->execute();

        $rows = $statement->fetchAll( PDO::FETCH_ASSOC );

        return (int)$rows[0]["count"];
    }

    /**
     * Moves the synonym identified by $synonymId to tag identified by $mainTagData
     *
     * @param mixed $synonymId
     * @param array $mainTagData
     */
    public function moveSynonym( $synonymId, $mainTagData )
    {
        $query = $this->handler->createUpdateQuery();
        $query
            ->update( $this->handler->quoteTable( "eztags" ) )
            ->set(
                $this->handler->quoteColumn( "parent_id" ),
                $query->bindValue( $mainTagData["parent_id"], null, PDO::PARAM_INT )
            )->set(
                $this->handler->quoteColumn( "main_tag_id" ),
                $query->bindValue( $mainTagData["id"], null, PDO::PARAM_INT )
            )->set(
                $this->handler->quoteColumn( "depth" ),
                $query->bindValue( $mainTagData["depth"], null, PDO::PARAM_INT )
            )->set(
                $this->handler->quoteColumn( "path_string" ),
                $query->bindValue( $this->getSynonymPathString( $synonymId, $mainTagData["path_string"] ), null, PDO::PARAM_STR )
            )->where(
                $query->expr->eq(
                    $this->handler->quoteColumn( "id" ),
                    $query->bindValue( $synonymId, null, PDO::PARAM_INT )
                )
            );

        $query->prepare()->execute();
    }

    /**
     * Creates a new tag using the given $createStruct below $parentTag
     *
     * @param \Netgen\TagsBundle\SPI\Persistence\Tags\CreateStruct $createStruct
     * @param array $parentTag
     *
     * @return int
     */
    public function create( CreateStruct $createStruct, array $parentTag = null )
    {
        $keywordValues = array_values( $createStruct->keywords );
        $languageCodes = array_keys( $createStruct->keywords );

        $query = $this->handler->createInsertQuery();
        $query
            ->insertInto( $this->handler->quoteTable( "eztags" ) )
            ->set(
                $this->handler->quoteColumn( "id" ),
                $this->handler->getAutoIncrementValue( "eztags", "id" )
            )->set(
                $this->handler->quoteColumn( "parent_id" ),
                $query->bindValue( $parentTag !== null ? (int)$parentTag["id"] : 0, null, PDO::PARAM_INT )
            )->set(
                $this->handler->quoteColumn( "main_tag_id" ),
                $query->bindValue( 0, null, PDO::PARAM_INT )
            )->set(
                $this->handler->quoteColumn( "keyword" ),
                $query->bindValue( $keywordValues[0], null, PDO::PARAM_STR )
            )->set(
                $this->handler->quoteColumn( "depth" ),
                $query->bindValue( $parentTag !== null ? (int)$parentTag["depth"] + 1 : 1, null, PDO::PARAM_INT )
            )->set(
                $this->handler->quoteColumn( "path_string" ),
                $query->bindValue( "dummy" ) // Set later
            )->set(
                $this->handler->quoteColumn( "modified" ),
                $query->bindValue( time(), null, PDO::PARAM_INT )
            )->set(
                $this->handler->quoteColumn( "remote_id" ),
                $query->bindValue( $createStruct->remoteId, null, PDO::PARAM_STR )
            )->set(
                $this->handler->quoteColumn( "main_language_id" ),
                $query->bindValue( $this->languageHandler->loadByLanguageCode( $languageCodes[0] )->id, null, PDO::PARAM_INT )
            )->set(
                $this->handler->quoteColumn( "language_mask" ),
                $query->bindValue( $this->generateLanguageMask( $createStruct->keywords, true ), null, PDO::PARAM_INT )
            );

        $query->prepare()->execute();

        $tagId = $this->handler->lastInsertId( $this->handler->getSequenceName( "eztags", "id" ) );
        $pathString = ( $parentTag !== null ? $parentTag["path_string"] : "/" ) . $tagId . "/";

        $query = $this->handler->createUpdateQuery();
        $query
            ->update( $this->handler->quoteTable( "eztags" ) )
            ->set(
                $this->handler->quoteColumn( "path_string" ),
                $query->bindValue( $pathString, null, PDO::PARAM_STR )
            )->where(
                $query->expr->eq(
                    $this->handler->quoteColumn( "id" ),
                    $query->bindValue( $tagId, null, PDO::PARAM_INT )
                )
            );

        $query->prepare()->execute();

        foreach ( $createStruct->keywords as $languageCode => $keyword )
        {
            $query = $this->handler->createInsertQuery();
            $query
                ->insertInto( $this->handler->quoteTable( "eztags_keyword" ) )
                ->set(
                    $this->handler->quoteColumn( "keyword_id" ),
                    $query->bindValue( $tagId, null, PDO::PARAM_INT )
                )->set(
                    $this->handler->quoteColumn( "language_id" ),
                    $query->bindValue( $this->languageHandler->loadByLanguageCode( $languageCode )->id, null, PDO::PARAM_INT )
                )->set(
                    $this->handler->quoteColumn( "keyword" ),
                    $query->bindValue( $keyword, null, PDO::PARAM_STR )
                )->set(
                    $this->handler->quoteColumn( "locale" ),
                    $query->bindValue( $languageCode, null, PDO::PARAM_STR )
                )->set(
                    $this->handler->quoteColumn( "status" ),
                    $query->bindValue( 1, null, PDO::PARAM_INT )
                );

            $query->prepare()->execute();
        }

        return $tagId;
    }

    /**
     * Updates an existing tag
     *
     * @param \Netgen\TagsBundle\SPI\Persistence\Tags\UpdateStruct $updateStruct
     * @param mixed $tagId
     */
    public function update( UpdateStruct $updateStruct, $tagId )
    {
        $query = $this->handler->createUpdateQuery();
        $query
            ->update( $this->handler->quoteTable( "eztags" ) )
            ->set(
                $this->handler->quoteColumn( "keyword" ),
                $query->bindValue( $updateStruct->keyword, null, PDO::PARAM_STR )
            )->set(
                $this->handler->quoteColumn( "remote_id" ),
                $query->bindValue( $updateStruct->remoteId, null, PDO::PARAM_STR )
            )->where(
                $query->expr->eq(
                    $this->handler->quoteColumn( "id" ),
                    $query->bindValue( $tagId, null, PDO::PARAM_INT )
                )
            );

        $query->prepare()->execute();
    }

    /**
     * Creates a new synonym using the given $keyword for tag $tag
     *
     * @param string $keyword
     * @param array $tag
     *
     * @return \Netgen\TagsBundle\SPI\Persistence\Tags\Tag
     */
    public function createSynonym( $keyword, array $tag )
    {
        $synonym = new Tag();

        $query = $this->handler->createInsertQuery();
        $query
            ->insertInto( $this->handler->quoteTable( "eztags" ) )
            ->set(
                $this->handler->quoteColumn( "id" ),
                $this->handler->getAutoIncrementValue( "eztags", "id" )
            )->set(
                $this->handler->quoteColumn( "parent_id" ),
                $query->bindValue( $synonym->parentTagId = $tag["parent_id"], null, PDO::PARAM_INT )
            )->set(
                $this->handler->quoteColumn( "main_tag_id" ),
                $query->bindValue( $synonym->mainTagId = $tag["id"], null, PDO::PARAM_INT )
            )->set(
                $this->handler->quoteColumn( "keyword" ),
                $query->bindValue( $synonym->keyword = $keyword, null, PDO::PARAM_STR )
            )->set(
                $this->handler->quoteColumn( "depth" ),
                $query->bindValue( $synonym->depth = $tag["depth"], null, PDO::PARAM_INT )
            )->set(
                $this->handler->quoteColumn( "path_string" ),
                $query->bindValue( "dummy" ) // Set later
            )->set(
                $this->handler->quoteColumn( "modified" ),
                $query->bindValue( $synonym->modificationDate = time(), null, PDO::PARAM_INT )
            )->set(
                $this->handler->quoteColumn( "remote_id" ),
                $query->bindValue( $synonym->remoteId = md5( uniqid( get_class( $this ), true ) ), null, PDO::PARAM_STR )
            );

        $query->prepare()->execute();

        $synonym->id = $this->handler->lastInsertId( $this->handler->getSequenceName( "eztags", "id" ) );
        $synonym->pathString = $this->getSynonymPathString( $synonym->id, $tag["path_string"] );

        $query = $this->handler->createUpdateQuery();
        $query
            ->update( $this->handler->quoteTable( "eztags" ) )
            ->set(
                $this->handler->quoteColumn( "path_string" ),
                $query->bindValue( $synonym->pathString, null, PDO::PARAM_STR )
            )->where(
                $query->expr->eq(
                    $this->handler->quoteColumn( "id" ),
                    $query->bindValue( $synonym->id, null, PDO::PARAM_INT )
                )
            );

        $query->prepare()->execute();

        return $synonym;
    }

    /**
     * Converts tag identified by $tagId to a synonym of tag identified by $mainTagData
     *
     * @param mixed $tagId
     * @param array $mainTagData
     */
    public function convertToSynonym( $tagId, $mainTagData )
    {
        $query = $this->handler->createUpdateQuery();
        $query
            ->update( $this->handler->quoteTable( "eztags" ) )
            ->set(
                $this->handler->quoteColumn( "parent_id" ),
                $query->bindValue( $mainTagData["parent_id"], null, PDO::PARAM_INT )
            )->set(
                $this->handler->quoteColumn( "main_tag_id" ),
                $query->bindValue( $mainTagData["id"], null, PDO::PARAM_INT )
            )->set(
                $this->handler->quoteColumn( "depth" ),
                $query->bindValue( $mainTagData["depth"], null, PDO::PARAM_INT )
            )->set(
                $this->handler->quoteColumn( "path_string" ),
                $query->bindValue( $this->getSynonymPathString( $tagId, $mainTagData["path_string"] ), null, PDO::PARAM_STR )
            )->set(
                $this->handler->quoteColumn( "modified" ),
                $query->bindValue( time(), null, PDO::PARAM_INT )
            )->where(
                $query->expr->eq(
                    $this->handler->quoteColumn( "id" ),
                    $query->bindValue( $tagId, null, PDO::PARAM_INT )
                )
            );

        $query->prepare()->execute();
    }

    /**
     * Transfers all tag attribute links from tag identified by $tagId into the tag identified by $targetTagId
     *
     * @param mixed $tagId
     * @param mixed $targetTagId
     */
    public function transferTagAttributeLinks( $tagId, $targetTagId )
    {
        $query = $this->handler->createSelectQuery();
        $query
            ->select( "*" )
            ->from( $this->handler->quoteTable( "eztags_attribute_link" ) )
            ->where(
                $query->expr->eq(
                    $this->handler->quoteColumn( "keyword_id" ),
                    $query->bindValue( $tagId, null, PDO::PARAM_INT )
                )
            );

        $statement = $query->prepare();
        $statement->execute();

        $rows = $statement->fetchAll( PDO::FETCH_ASSOC );

        $updateLinkIds = array();
        $deleteLinkIds = array();

        foreach ( $rows as $row )
        {
            $query = $this->handler->createSelectQuery();
            $query
                ->select(
                    $this->handler->quoteColumn( "id" )
                )
                ->from( $this->handler->quoteTable( "eztags_attribute_link" ) )
                ->where(
                    $query->expr->lAnd(
                        $query->expr->eq(
                            $this->handler->quoteColumn( "objectattribute_id" ),
                            $query->bindValue( $row["objectattribute_id"], null, PDO::PARAM_INT )
                        ),
                        $query->expr->eq(
                            $this->handler->quoteColumn( "objectattribute_version" ),
                            $query->bindValue( $row["objectattribute_version"], null, PDO::PARAM_INT )
                        ),
                        $query->expr->eq(
                            $this->handler->quoteColumn( "keyword_id" ),
                            $query->bindValue( $targetTagId, null, PDO::PARAM_INT )
                        )
                    )
                );

            $statement = $query->prepare();
            $statement->execute();

            $targetRows = $statement->fetchAll( PDO::FETCH_ASSOC );

            if ( empty( $targetRows ) )
            {
                $updateLinkIds[] = $row["id"];
            }
            else
            {
                $deleteLinkIds[] = $row["id"];
            }
        }

        if ( !empty( $deleteLinkIds ) )
        {
            $query = $this->handler->createDeleteQuery();
            $query
                ->deleteFrom( $this->handler->quoteTable( "eztags_attribute_link" ) )
                ->where(
                    $query->expr->in(
                        $this->handler->quoteColumn( "id" ),
                        $deleteLinkIds
                    )
                );

            $query->prepare()->execute();
        }

        if ( !empty( $updateLinkIds ) )
        {
            $query = $this->handler->createUpdateQuery();
            $query
                ->update( $this->handler->quoteTable( "eztags_attribute_link" ) )
                ->set(
                    $this->handler->quoteColumn( "keyword_id" ),
                    $query->bindValue( $targetTagId )
                )->where(
                    $query->expr->in(
                        $this->handler->quoteColumn( "id" ),
                        $updateLinkIds
                    )
                );

            $query->prepare()->execute();
        }
    }

    /**
     * Moves a tag identified by $sourceTagData into new parent identified by $destinationParentTagData
     *
     * @param array $sourceTagData
     * @param array $destinationParentTagData
     *
     * @return array Tag data of the updated root tag
     */
    public function moveSubtree( array $sourceTagData, array $destinationParentTagData )
    {
        $fromPathString = $sourceTagData["path_string"];

        $query = $this->handler->createSelectQuery();
        $query
            ->select(
                $this->handler->quoteColumn( "id" ),
                $this->handler->quoteColumn( "parent_id" ),
                $this->handler->quoteColumn( "main_tag_id" ),
                $this->handler->quoteColumn( "path_string" )
            )
            ->from( $this->handler->quoteTable( "eztags" ) )
            ->where(
                $query->expr->lOr(
                    $query->expr->like(
                        $this->handler->quoteColumn( "path_string" ),
                        $query->bindValue( $fromPathString . "%", null, PDO::PARAM_STR )
                    ),
                    $query->expr->eq(
                        $this->handler->quoteColumn( "main_tag_id" ),
                        $query->bindValue( $sourceTagData["id"], null, PDO::PARAM_INT )
                    )
                )
            );

        $statement = $query->prepare();
        $statement->execute();

        $rows = $statement->fetchAll( PDO::FETCH_ASSOC );

        $oldParentPathString = implode( "/", array_slice( explode( "/", $fromPathString ), 0, -2 ) ) . "/";
        $timestamp = time();
        foreach ( $rows as $row )
        {
            // Prefixing ensures correct replacement when there is no parent
            $newPathString = str_replace(
                "prefix" . $oldParentPathString,
                $destinationParentTagData["path_string"],
                "prefix" . $row["path_string"]
            );

            $newParentId = $row["parent_id"];
            if ( $row["path_string"] === $fromPathString || $row["main_tag_id"] == $sourceTagData["id"] )
            {
                $newParentId = (int)implode( "", array_slice( explode( "/", $newPathString ), -3, 1 ) );
            }

            $newDepth = substr_count( $newPathString, "/" ) - 1;

            if ( $row["id"] == $sourceTagData["id"] )
            {
                $sourceTagData["parent_id"] = $newParentId;
                $sourceTagData["depth"] = $newDepth;
                $sourceTagData["path_string"] = $newPathString;
                $sourceTagData["modified"] = $timestamp;
            }

            $query = $this->handler->createUpdateQuery();
            $query
                ->update( $this->handler->quoteTable( "eztags" ) )
                ->set(
                    $this->handler->quoteColumn( "path_string" ),
                    $query->bindValue( $newPathString, null, PDO::PARAM_STR )
                )->set(
                    $this->handler->quoteColumn( "depth" ),
                    $query->bindValue( $newDepth, null, PDO::PARAM_INT )
                )->set(
                    $this->handler->quoteColumn( "parent_id" ),
                    $query->bindValue( $newParentId, null, PDO::PARAM_INT )
                )->set(
                    $this->handler->quoteColumn( "modified" ),
                    $query->bindValue( $timestamp, null, PDO::PARAM_INT )
                )->where(
                    $query->expr->eq(
                        $this->handler->quoteColumn( "id" ),
                        $query->bindValue( $row["id"], null, PDO::PARAM_INT )
                    )
                );

            $query->prepare()->execute();
        }

        return $sourceTagData;
    }

    /**
     * Deletes tag identified by $tagId, including its synonyms and all tags under it
     *
     * If $tagId is a synonym, only the synonym is deleted
     *
     * @param mixed $tagId
     */
    public function deleteTag( $tagId )
    {
        $query = $this->handler->createSelectQuery();
        $query
            ->select( "id" )
            ->from( $this->handler->quoteTable( "eztags" ) )
            ->where(
                $query->expr->lOr(
                    $query->expr->like(
                        $this->handler->quoteColumn( "path_string" ),
                        $query->bindValue( "%/" . (int)$tagId . "/%", null, PDO::PARAM_STR )
                    ),
                    $query->expr->eq(
                        $this->handler->quoteColumn( "main_tag_id" ),
                        $query->bindValue( $tagId, null, PDO::PARAM_INT )
                    )
                )
            );

        $statement = $query->prepare();
        $statement->execute();

        $tagIds = array();
        while ( $row = $statement->fetch( PDO::FETCH_ASSOC ) )
        {
            $tagIds[] = (int)$row["id"];
        }

        if ( empty( $tagIds ) )
        {
            return;
        }

        $query = $this->handler->createDeleteQuery();
        $query
            ->deleteFrom( $this->handler->quoteTable( "eztags_attribute_link" ) )
            ->where(
                $query->expr->in(
                    $this->handler->quoteColumn( "keyword_id" ),
                    $tagIds
                )
            );

        $query->prepare()->execute();

        $query = $this->handler->createDeleteQuery();
        $query
            ->deleteFrom( $this->handler->quoteTable( "eztags_keyword" ) )
            ->where(
                $query->expr->in(
                    $this->handler->quoteColumn( "keyword_id" ),
                    $tagIds
                )
            );

        $query->prepare()->execute();

        $query = $this->handler->createDeleteQuery();
        $query
            ->deleteFrom( $this->handler->quoteTable( "eztags" ) )
            ->where(
                $query->expr->in(
                    $this->handler->quoteColumn( "id" ),
                    $tagIds
                )
            );

        $query->prepare()->execute();
    }

    /**
     * Updated subtree modification time for all tags in path
     *
     * @param string $pathString
     * @param int $timestamp
     */
    public function updateSubtreeModificationTime( $pathString, $timestamp = null )
    {
        $tagIds = array_filter( explode( "/", $pathString ) );

        if ( empty( $tagIds ) )
        {
            return;
        }

        $query = $this->handler->createUpdateQuery();
        $query
            ->update( $this->handler->quoteTable( "eztags" ) )
            ->set(
                $this->handler->quoteColumn( "modified" ),
                $query->bindValue( $timestamp ?: time(), null, PDO::PARAM_INT )
            )
            ->where(
                $query->expr->in(
                    $this->handler->quoteColumn( "id" ),
                    $tagIds
                )
            );

        $query->prepare()->execute();
    }

    /**
     * Creates a select query for tag objects
     *
     * Creates a select query with all necessary joins to fetch a complete
     * tag. Does not apply any WHERE conditions.
     *
     * @return \eZ\Publish\Core\Persistence\Database\SelectQuery
     */
    protected function createTagFindQuery()
    {
        /** @var $query \eZ\Publish\Core\Persistence\Database\SelectQuery */
        $query = $this->handler->createSelectQuery();
        $query->select(
            // Tag
            $this->handler->aliasedColumn( $query, 'id', 'eztags' ),
            $this->handler->aliasedColumn( $query, 'parent_id', 'eztags' ),
            $this->handler->aliasedColumn( $query, 'main_tag_id', 'eztags' ),
            $this->handler->aliasedColumn( $query, 'keyword', 'eztags' ),
            $this->handler->aliasedColumn( $query, 'depth', 'eztags' ),
            $this->handler->aliasedColumn( $query, 'path_string', 'eztags' ),
            $this->handler->aliasedColumn( $query, 'modified', 'eztags' ),
            $this->handler->aliasedColumn( $query, 'remote_id', 'eztags' ),
            $this->handler->aliasedColumn( $query, 'main_language_id', 'eztags' ),
            $this->handler->aliasedColumn( $query, 'language_mask', 'eztags' ),
            // Tag keywords
            $this->handler->aliasedColumn( $query, 'keyword', 'eztags_keyword' ),
            $this->handler->aliasedColumn( $query, 'locale', 'eztags_keyword' )
        )->from(
            $this->handler->quoteTable( 'eztags' )
        )
        // @todo: Joining with eztags_keyword is probably a VERY bad way to gather that information
        // since it creates an additional cartesian product with translations.
        ->leftJoin(
            $this->handler->quoteTable( 'eztags_keyword' ),
            $query->expr->lAnd(
                // eztags_keyword.locale is also part of the PK but can't be
                // easily joined with something at this level
                $query->expr->eq(
                    $this->handler->quoteColumn( 'keyword_id', 'eztags_keyword' ),
                    $this->handler->quoteColumn( 'id', 'eztags' )
                ),
                $query->expr->eq(
                    $this->handler->quoteColumn( 'status', 'eztags_keyword' ),
                    $query->bindValue( 1, null, PDO::PARAM_INT )
                )
            )
        );

        return $query;
    }

    /**
     * Returns the path string of a synonym for main tag path string
     *
     * @param mixed $synonymId
     * @param string $mainTagPathString
     *
     * @return string
     */
    protected function getSynonymPathString( $synonymId, $mainTagPathString )
    {
        $pathStringElements = explode( "/", trim( $mainTagPathString, "/" ) );
        array_pop( $pathStringElements );

        return ( !empty( $pathStringElements ) ? "/" . implode( "/", $pathStringElements ) : "" ) . "/" . (int)$synonymId . "/";
    }

    /**
     * Generates a language mask for provided keywords
     *
     * @param string[] $keywords
     * @param boolean $alwaysAvailable
     *
     * @return int
     */
    protected function generateLanguageMask( array $keywords, $alwaysAvailable = true )
    {
        $languages = array();

        foreach ( $keywords as $languageCode => $keyword )
        {
            $languages[$languageCode] = true;
        }

        if ( $alwaysAvailable )
        {
            $languages['always-available'] = true;
        }

        return $this->languageMaskGenerator->generateLanguageMask( $languages );
    }
}
