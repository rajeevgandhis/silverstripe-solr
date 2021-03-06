<?php
/**
 * A page type specifically used for displaying search results.
 *
 * This is an alternative encapsulation of search logic as it comprises much more than the out of the
 * box example. To use this instead of the default implementation, your search form call in Page should first
 * retrieve the SolrSearchPage to use as its context.
 * 
 *
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 * @license http://silverstripe.org/bsd-license/
 */
class SolrSearchPage extends Page {
    public static $db = array(
		'ResultsPerPage' => 'Int',
		'SearchType' => 'Varchar(64)',
		'SortBy' => "Varchar(64)",
		'SortDir' => "Enum('Ascending,Descending')",
		'QueryType'	=> 'Varchar',
		'StartWithListing'	=> 'Boolean',			// whether to start display with a *:* search
		'SearchOnFields'	=> 'MultiValueField',
		'BoostFields'		=> 'MultiValueField',
		'FacetFields'		=> 'MultiValueField',
	);

	/**
	 *
	 * The facets we're interested in for this search page. This will be made a little more
	 * flexible in later releases.
	 * 
	 * for example with the alchemiser module -
	 * 
	 * array (
	 * 'AlcKeywords_mt',
	 * 	'AlcPerson_mt',
	 * 	'AlcCompany_mt',
	 * 	'AlcOrganization_mt',
	 * );
	 *
	 * @var array
	 */
	public static $facets = array();

	/**
	 * A local cache of the current query the user is executing based
	 * on data in the request
	 *
	 * @var SolrResultSet
	 */
	protected $query;

	/**
	 * @var SolrSearchService
	 */
	protected $solr;

	/**
	 * Used for the url param
	 *
	 * @var String
	 */
	public static $filter_param = 'filter';
	
	/**
	 *
	 * @var array
	 */
	public static $additional_search_types = array();

	public function getCMSFields() {
		$fields = parent::getCMSFields();

		$fields->addFieldToTab('Root.Content.Main', new CheckboxField('StartWithListing', _t('SolrSearchPage.START_LISTING', 'Display initial listing')), 'Content');

		$perPage = array('5' => '5', '10' => '10', '15' => '15', '20' => '20');
		$fields->addFieldToTab('Root.Content.Main',new DropdownField('ResultsPerPage', _t('SolrSearchPage.RESULTS_PER_PAGE', 'Results per page'), $perPage), 'Content');

		if (!$this->SortBy) {
			$this->SortBy = 'Created';
		}

		$objFields = $this->getSelectableFields();
		$fields->addFieldToTab('Root.Content.Main', new DropdownField('SortBy', _t('SolrSearchPage.SORT_BY', 'Sort By'), $objFields), 'Content');
		$fields->addFieldToTab('Root.Content.Main', new DropdownField('SortDir', _t('SolrSearchPage.SORT_DIR', 'Sort Direction'), $this->dbObject('SortDir')->enumValues()), 'Content');

		$types = SiteTree::page_type_classes();
		$source = array_combine($types, $types);
		asort($source);
		$source = array_merge(array('' => 'Any'), $source);
		
		$source = array_merge($source, self::$additional_search_types);
		
		$optionsetField = new DropdownField('SearchType', _t('SolrSearchPage.PAGE_TYPE', 'Search pages of type'), $source, 'Any');
		$fields->addFieldToTab('Root.Content.Main', $optionsetField, 'Content');

		$fields->addFieldToTab('Root.Content.Main', new MultiValueDropdownField('SearchOnFields', _t('SolrSearchPage.INCLUDE_FIELDS', 'Search On Fields'), $objFields), 'Content');

		$parsers = singleton('SolrSearchService')->getQueryBuilders();
		$options = array();
		foreach ($parsers as $key => $objCls) {
			$obj = new $objCls;
			$options[$key] = $obj->title;
		}

		$fields->addFieldToTab('Root.Content.Main', new DropdownField('QueryType', _t('SolrSearchPage.QUERY_TYPE', 'Query Type'), $options), 'Content');

		$boostVals = array();
		for ($i = 1; $i <= 5; $i++) {
			$boostVals[$i] = $i;
		}

		$fields->addFieldToTab(
			'Root.Content.Main', 
			new KeyValueField('BoostFields', _t('SolrSearchPage.BOOST_FIELDS', 'Boost values'), $objFields, $boostVals),
			'Content'
		);
		
		$fields->addFieldToTab(
			'Root.Content.Main', 
			new MultiValueDropdownField('FacetFields', _t('SolrSearchPage.FACET_FIELDS', 'Fields to create facets for'), $objFields),
			'Content'
		);

		return $fields;
	}

	/**
	 * Return the fields that can be selected for sorting operations.
	 *
	 * @param String $listType
	 * @return string
	 */
	public function getSelectableFields($listType=null) {
		if (!$listType) {
			$listType = strlen($this->SearchType) ? $this->SearchType : 'Page';
		}
		
		$availableFields = singleton('SolrSearchService')->getSearchableFieldsFor($listType);
		$objFields = array_combine(array_keys($availableFields), array_keys($availableFields));
		$objFields['LastEdited'] = 'LastEdited';
		$objFields['Created'] = 'Created';
		$objFields['ID'] = 'ID';
		$objFields['score'] = 'Score';

		ksort($objFields);
		return $objFields;
	}

	/**
	 * Get the solr instance. 
	 * 
	 * Note that we do this as a method just in case we decide in future
	 * that different pages can utilise different solr instances.. 
	 */
	public function getSolr() {
		if (!$this->solr) {
			$this->solr = singleton('SolrSearchService');
		}
		return $this->solr;
	}
	
	/**
	 * Figures out the list of fields to use in faceting, based on configured / defaults
	 */
	public function fieldsForFacets() {
		$fields = self::$facets;
		if ($this->FacetFields && $ff = $this->FacetFields->getValues()) {
			$fields = array();
			$type = (strlen($this->SearchType) ? $this->SearchType : 'Page');  
			foreach ($ff as $f) {
				$fields[] = $this->getSolr()->getSolrFieldName($f, $type);
			}
		}

		return $fields;
	}

	/**
	 * Get the currently active query for this page, if any
	 * 
	 * @return SolrResultSet
	 */
	public function getQuery() {
		if ($this->query) {
			return $this->query;
		}

		if (!$this->getSolr()->isConnected()) {
			return null;
		}

		$query = null;
		$builder = $this->getSolr()->getQueryBuilder($this->QueryType);
		
		if (isset($_GET['Search'])) {
			$query = $_GET['Search'];

			// lets convert it to a base solr query
			$builder->baseQuery($query);
		}

		$sortBy = isset($_GET['SortBy']) ? $_GET['SortBy'] : $this->SortBy;
		$sortDir = isset($_GET['SortDir']) ? $_GET['SortDir'] : $this->SortDir;
		$type = (strlen($this->SearchType) ? $this->SearchType : 'Page');  

		$fields = $this->getSelectableFields($this->SearchType);
		
		// if we've explicitly set a sort by, then we want to make sure we have a type
		// so we can resolve what the field name in solr is
		if (!$type && $sortBy) {
			// default to page
			$type = 'Page';
		}

		if (!isset($fields[$sortBy])) {
			$sortBy = 'score';
		}

		$sortDir = $sortDir == 'Ascending' ? 'asc' : 'desc';

		$activeFacets = $this->getActiveFacets();
		if (count($activeFacets)) {
			foreach ($activeFacets as $facetName => $facetValues) {
				foreach ($facetValues as $value) {
					$builder->andWith($facetName, $value);
				}
			}
		}

		$offset = isset($_GET['start']) ? $_GET['start'] : 0;
		$limit = isset($_GET['limit']) ? $_GET['limit'] : ($this->ResultsPerPage ? $this->ResultsPerPage : 10);

		if ($type) {
			$sortBy = singleton('SolrSearchService')->getSortFieldName($sortBy, $type);
			$builder->andWith('ClassNameHierarchy_ms', $type);
		}

		if (!$sortBy) {
			$sortBy = 'score';
		}

		$selectedFields = $this->SearchOnFields->getValues();
		if (count($selectedFields)) {
			$mappedFields = array();
			foreach ($selectedFields as $field) {
				$mappedField = $this->getSolr()->getSolrFieldName($field, $type);
				if (!$mappedField) {
					throw new Exception("Field $field does not have a proper mapping");
				}
				$mappedFields[] = $mappedField;
			}
			$builder->queryFields($mappedFields);
		}

		if ($boost = $this->BoostFields->getValues()) {
			$boostSetting = array();
			foreach ($boost as $field => $amount) {
				if ($amount > 0) {
					$boostSetting[$this->getSolr()->getSolrFieldName($field, $type)] = $amount;
				}
			}
			$builder->boost($boostSetting);
		}

		$params = array(
			'facet' => 'true',
			'facet.field' => $this->fieldsForFacets(),
			'facet.limit' => 10,
			'facet.mincount' => 1,
			'sort' => "$sortBy $sortDir",
			'fl' => '*,score'
		);

		$this->query = $this->getSolr()->query($builder, $offset, $limit, $params);
		return $this->query;
	}

	/**
	 * Gets a list of facet based filters
	 */
	public function getActiveFacets() {
		return isset($_GET[self::$filter_param]) ? $_GET[self::$filter_param] : array();
	}

	/**
	 * Returns a url parameter string that was just used to execute the current query.
	 *
	 * This is useful for ensuring the parameters used in the search can be passed on again
	 * for subsequent queries.
	 *
	 * @param array $exclusions
	 *			A list of elements that should be excluded from the final query string
	 *
	 * @return String
	 */
	function SearchQuery() {
		$parts = parse_url($_SERVER['REQUEST_URI']);
		if(!$parts) {
			throw new InvalidArgumentException("Can't parse URL: " . $uri);
		}

		// Parse params and add new variable
		$params = array();
		if(isset($parts['query'])) {
			parse_str($parts['query'], $params);
			if (count($params)) {
				return http_build_query($params);
			}
		}
	}

	/**
	 * Get the list of facet values for the given term
	 *
	 * @param String $term
	 */
	public function currentFacets($term=null) {
		if (!$this->getQuery()) {
			return new DataObjectSet();
		}
		$facets = $this->getQuery()->getFacets();

		if ($term) {
			// return just that term
			$ret = isset($facets[$term]) ? $facets[$term] : null;
			// lets update them all and add a link parameter
			if ($ret) {
				foreach ($ret as $facetTerm) {
					$sq = $this->SearchQuery();
					$sep = strlen($sq) ? '&amp;' : '';
					$facetTerm->SearchLink = $this->Link('results') . '?' . $sq .$sep. self::$filter_param . "[$term][]=$facetTerm->Name";
					$facetTerm->QuotedSearchLink = $this->Link('results') . '?' . $sq .$sep. self::$filter_param . "[$term][]=&quot;$facetTerm->Name&quot;";
				}
			}

			return new DataObjectSet($ret);
		}

		return $facets;
	}
}

class SolrSearchPage_Controller extends Page_Controller {

	protected function getSolr() {
		return $this->data()->getSolr();
	}
	
	public function index() {
		if ($this->StartWithListing) {
			$_GET['SortBy'] = isset($_GET['SortBy']) ? $_GET['SortBy'] : $this->data()->SortBy;
			$_GET['SortDir'] = isset($_GET['SortDir']) ? $_GET['SortDir'] : $this->data()->SortDir;
			$_GET['Search'] = '*';
			$this->DefaultListing = true;
			
			return $this->results();
		}
		return array();
	}

	public function Form() {
		$fields = new FieldSet(
			new TextField('Search', _t('SolrSearchPage.SEARCH','Search'), isset($_GET['Search']) ? $_GET['Search'] : '')
		);

		$objFields = $this->data()->getSelectableFields();
		$objFields = array_merge(array('' => 'Any'), $objFields);
		$sortBy = isset($_GET['SortBy']) ? $_GET['SortBy'] : $this->data()->SortBy;
		$sortDir = isset($_GET['SortDir']) ? $_GET['SortDir'] : $this->data()->SortDir;
		$fields->push(new DropdownField('SortBy', _t('SolrSearchPage.SORT_BY', 'Sort By'), $objFields, $sortBy));
		$fields->push(new DropdownField('SortDir', _t('SolrSearchPage.SORT_DIR', 'Sort Direction'), $this->data()->dbObject('SortDir')->enumValues(), $sortDir));

		$actions = new FieldSet(new FormAction('results', _t('SolrSearchPage.DO_SEARCH', 'Search')));
		
		$form = new Form($this, 'Form', $fields, $actions);
		$form->addExtraClass('searchPageForm');
		$form->setFormMethod('GET');
		$form->disableSecurityToken();
		return $form;
	}

	public function FacetCrumbs() {
		$activeFacets = $this->data()->getActiveFacets();
		$parts = array();
		$queryString = $this->data()->SearchQuery();
		if (count($activeFacets)) {
			foreach ($activeFacets as $facetName => $facetValues) {
				foreach ($facetValues as $i => $v) {
					$item = new stdClass();
					$item->Name = $v;
					$paramName = urlencode(SolrSearchPage::$filter_param . '[' . $facetName . '][' . $i . ']') .'='. urlencode($item->Name);
					$item->RemoveLink = $this->Link('results') . '?' . str_replace($paramName, '', $queryString);
					$parts[] = $item;
				}
			}
		}

		return new DataObjectSet($parts);
	}

	/**
	 * Process and render search results
	 */
	function results($data = null, $form = null){
		$query = $this->data()->getQuery();

		$term = isset($_GET['Search']) ? Convert::raw2xml($_GET['Search']) : '';

	  	$data = array(
	     	'Results' => $query ? $query->getDataObjects() : new DataObjectSet(),
	     	'Query' => $term,
	      	'Title' => 'Search Results'
	  	);

	  	return $this->customise($data)->renderWith(array('SolrSearchPage_results', 'SolrSearchPage', 'Page'));
	}
}
