<?php

/**
 * @package     omeka
 * @subpackage  solr-search
 * @copyright   2012 Rector and Board of Visitors, University of Virginia
 * @license     http://www.apache.org/licenses/LICENSE-2.0.html
 */


/**
 * This handles indexes data from the addons.
 **/
class SolrSearch_Addon_Indexer
{


    /**
     * This is the database interface.
     *
     * @var Omeka_Db
     **/
    var $db;


    function __construct($db)
    {
        $this->db = $db;
    }


    /**
     * This creates a Solr-style name for an addon and field.
     *
     * @param SolrSearch_Addon_Addon $addon This is the addon.
     * @param string                 $field The field to get.
     *
     * @return string $name The Solr name.
     * @author Eric Rochester <erochest@virginia.edu>
     **/
    public function makeSolrName($addon, $field)
    {
        return "{$addon->name}_{$field}_t";
    }


    /**
     * This gets all the records in the database matching all the addons passed
     * in and returns a list of Solr documents for indexing.
     *
     * @param associative array of SolrSearch_Addon_Addon $addons The addon
     * configuration information about the records to index.
     *
     * @return array of Apache_Solr_Document $docs The documents to index.
     * @author Eric Rochester <erochest@virginia.edu>
     **/
    public function indexAll($addons)
    {
        $docs = array();

        foreach ($addons as $name => $addon) {
            $docs = array_merge($docs, $this->indexAllAddon($addon));
        }

        return $docs;
    }


    /**
     * This gets all the records associated with a single addon for indexing.
     *
     * @param SolrSearch_Addon_Addon The addon to pull records for.
     *
     * @return array of Apache_Solr_Documents $docs The documents to index.
     * @author Eric Rochester <erochest@virginia.edu>
     **/
    public function indexAllAddon($addon)
    {
        $docs = array();
        $table  = $this->db->getTable($addon->table);

        if ($this->_tableExists($table->getTableName())) {
            $select = $this->buildSelect($table, $addon);
            $rows   = $table->fetchObjects($select);

            foreach ($rows as $record) {
                $doc = $this->indexRecord($record, $addon);
                $docs[] = $doc;
            }
        }

        return $docs;
    }


    /**
     * This tests whether the table exists.
     *
     * @param Omeka_Table $table The name of the table to check for.
     *
     * @return bool
     * @author Eric Rochester <erochest@virginia.edu>
     **/
    protected function _tableExists($table)
    {
        $exists = false;

        try {
            $info   = $this->db->describeTable($table);
            $exists = !empty($info);
        } catch (Zend_Db_Exception $e) {
        }

        return $exists;
    }


    /**
     * This returns an Apache_Solr_Document to index, if the addons say it
     * should be.
     *
     * @param Omeka_Record $record The record to index.
     * @param associative array of SolrSearch_Addon_Addon $addons The
     * configuration controlling how records are indexed.
     *
     * @return Apache_Solr_Document|null
     * @author Eric Rochester <erochest@virginia.edu>
     **/
    public function indexRecord($record, $addon)
    {
        $doc = new Apache_Solr_Document();

        $doc->id = "{$addon->table}_{$record->id}";
        $doc->addField('model', $addon->table);
        $doc->addField('modelid', $record->id);

        // extend $doc to include public / private records
        $doc->addField('public', $this->isRecordPublic($record, $addon));

        $titleField = $addon->getTitleField();
        foreach ($addon->fields as $field) {
            $solrName = $this->makeSolrName($addon, $field->name);

            if (!is_null($field->remote)) {
                $value = $this->getRemoteValue($record, $field);
            } else if (!is_null($field->metadata)) {
                $value = $this->getMetadataValue($record, $field);
            } else {
                $value = $this->getLocalValue($record, $field);
            }

            foreach ($value as $v) {
                $doc->addField($solrName, $v);

                if (!is_null($titleField) && $titleField->name === $field->name) {
                    $doc->addField('title', $v);
                }
            }
        }

        if ($addon->tagged) {
            foreach ($record->getTags() as $tag) {
                $doc->addField('tag', $tag->name);
            }
        }

        if ($addon->resultType) {
            $doc->addField('resulttype', $addon->resultType);
        }

        return $doc;
    }


    /**
     * This returns a value that is local to the record.
     *
     * @param Omeka_Record           $record The record to get the value from.
     * @param SolrSearch_Addon_Field $field  The field that defines where to get
     * the value.
     *
     * @return mixed $value The value of the field in the record.
     * @author Eric Rochester <erochest@virginia.edu>
     **/
    protected function getLocalValue($record, $field)
    {
        $value = array();
        $value[] = SolrSearch_Helpers_Index::filterHTML(
            $record[$field->name],
            $field->is_html
        );

        return $value;
    }


    /**
     * This returns a value that is remotely attached to the record.
     *
     * @param Omeka_Record           $record The record to get the value from.
     * @param SolrSearch_Addon_Field $field  The field that defines where to get
     * the value.
     *
     * @return mixed $value The value of the field in the record.
     * @author Eric Rochester <erochest@virginia.edu>
     **/
    protected function getRemoteValue($record, $field)
    {
        $value = array();

        $table  = $this->db->getTable($field->remote->table);

        $select = $table->getSelect();
        $select->where(
            "{$table->getTableAlias()}.{$field->remote->key}={$record->id}"
        );

        $rows   = $table->fetchObjects($select);
        foreach ($rows as $item) {
            $value[] = SolrSearch_Helpers_Index::filterHTML(
                $item[$field->name],
                $field->is_html
            );
        }

        return $value;
    }


    /**
     * This returns a value that is metadata to the record.
     *
     * @param Omeka_Record           $record The record to get the value from.
     * @param SolrSearch_Addon_Field $field  The field that defines where to get
     * the value.
     *
     * @return mixed $value The value of the field in the record.
     * @author Eric Rochester <erochest@virginia.edu>
     **/
    protected function getMetadataValue($record, $field)
    {
        $value = array();

        $texts = $record->getElementTexts(
            $field->metadata[0],
            $field->metadata[1]
        );

        foreach ($texts as $text) {
            $value[] = SolrSearch_Helpers_Index::filterHTML(
                $text->text,
                $field->is_html || $text->html
            );
        }

        return $value;
    }


    /**
     * This builds a query for returning all the records to index from the
     * database.
     *
     * @param Omeka_Db_Table         $table The table to create the SQL for.
     * @param SolrSearch_Addon_Addon $addon The addon to generate SQL for.
     *
     * @return Omeka_Db_Select $select The select statement to execute for the
     * query.
     * @author Eric Rochester <erochest@virginia.edu>
     **/
    public function buildSelect($table, $addon)
    {
        $select = $table
            ->select()
            ->from($table->getTableName());

        return $select;
    }


    /**
     * This returns true if this addon (or one of its ancestors) are flagged.
     *
     * @param Omeka_Record $record The Omeka record to check if public.
     * @param SolrSearch_Addon_Addon $addon The addon for the record.
     *
     * @return bool $indexed A flag indicating whether the record is public.
     * @author Eric Rochester <erochest@virginia.edu>
     **/
    public function isRecordPublic($record, $addon)
    {
        $public = true;

        if (is_null($record)) {

        } else if (!is_null($addon->flag)) {
            $flag = $addon->flag;
            $public = $record->$flag;

        } else if (!is_null($addon->parentAddon)) {
            $key    = $addon->parentKey;
            $table  = $this->db->getTable($addon->parentAddon->table);
            $parent = $table->find($record->$key);

            $public = $this->isRecordPublic($parent, $addon->parentAddon);
        }

        return $public;
    }


}
