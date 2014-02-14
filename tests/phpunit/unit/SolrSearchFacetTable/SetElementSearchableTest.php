<?php

/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4 cc=80; */

/**
 * @package     omeka
 * @subpackage  solr-search
 * @copyright   2012 Rector and Board of Visitors, University of Virginia
 * @license     http://www.apache.org/licenses/LICENSE-2.0.html
 */


class SolrSearchFacetTableTest_SetElementSearchable
    extends SolrSearch_Test_AppTestCase
{


    /**
     * `setElementSearchable` should flip on the indexing flag for the facet
     * that corresponds to the passed element.
     */
    public function testFindByName()
    {

        $element = $this->elementTable->findByElementSetNameAndElementName(
            'Dublin Core', 'Date'
        );

        // Facet not searchable.
        $facet = $this->facetTable->findByElement($element);
        $this->assertEquals(0, $facet->is_displayed);

        $this->facetTable->setElementSearchable('Dublin Core', 'Date');

        // Should make facet searchable.
        $facet = $this->facetTable->findByElement($element);
        $this->assertEquals(1, $facet->is_displayed);

    }


}