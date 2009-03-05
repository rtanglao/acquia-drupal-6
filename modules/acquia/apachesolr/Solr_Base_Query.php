<?php
// $Id: Solr_Base_Query.php,v 1.1.4.20 2009/02/27 04:32:59 pwolanin Exp $

class Solr_Base_Query {

  /**
   * Extract all uses of one named field from a filter string e.g. 'type:book'
   */
  static function field_extract(&$filters, $name) {
    $queries = array();
    $values = array();
    // Range queries.  The "TO" is case-sensitive.
    $patterns[] = '/(^| )'. $name .':(\[\S+ TO \S+\])/';
    // Match quoted values.
    $patterns[] = '/(^| )'. $name .':"([^"]*)"/';
    // Match unquoted values.
    $patterns[] = '/(^| )'. $name .':([^ ]*)/';
    foreach ($patterns as $p) {
      if (preg_match_all($p, $filters, $matches)) {
        $queries = array_merge($matches[0], $queries);
        $values = array_merge($matches[2], $values);
      }
      // Update the local copy of $filters by removing all matches.
      $filters = trim(str_replace($matches[0], '', $filters));
    }
    return array('queries' => $queries, 'values' => $values);
  }

  /**
   * Takes an array $field and combines the #name and #value in a way
   * suitable for use in a Solr query.
   */
  static function make_field(array $field) {
    // If the field value has spaces, or : in it, wrap it in double quotes.
    // unless it is a range query.
    if (preg_match('/[ :]/', $field['#value']) && !preg_match('/\[\S+ TO \S+\]/', $field['#value'])) {
      $field['#value'] = '"'. $field['#value']. '"';
    }
    return $field['#name'] . ':' . $field['#value'];
  }

  /**
   * Static shared by all instances, used to increment ID numbers.
   */
  protected static $idCount = 0;

  /**
   * Each query/subquery will have a unique ID
   */
  public $id;

  /**
   * A keyed array where the key is a position integer and the value
   * is an array with #name and #value properties.  Each value is a
   * used for filter queries, e.g. array('#name' => 'uid', '#value' => 0)
   * for anonymous content.

   */
  protected $fields;

  /**
   * The complete filter string for a query.  Usually from $_GET['filters']
   * Contains name:value pairs for filter queries.  For example,
   * "type:book" for book nodes.
   */
  protected $filters;

  /**
   * A mapping of field names from the URL to real index field names.
   */
  protected $field_map = array();

  /**
   * An array of subqueries.
   */
  protected $subqueries = array();

  /**
   * The query path (search keywords).
   */
  protected $querypath;

  /**
   * Apache_Solr_Service object
   */
  protected $solr;

  /**
   * @param $solr
   *  An instantiated Apache_Solr_Service Object.
   *  Can be instantiated from apachesolr_get_solr().
   *
   * @param $querypath
   *   The string that a user would type into the search box. Suitable input
   *   may come from search_get_keys()
   *
   * @param $filterstring
   *   Key and value pairs that are applied as a filter query.
   *
   * @param $sortstring
   *   Visible string telling solr how to sort - added to output querystring.
   */
  function __construct($solr, $querypath, $filterstring, $sortstring) {
    $this->solr = $solr;
    $this->querypath = trim($querypath);
    $this->filters = trim($filterstring); 
    $this->solrsort = trim($sortstring);
    $this->id = ++self::$idCount;
    $this->parse_filters();
  }

  function __clone() {
    $this->id = ++self::$idCount;
  }

  function add_field($field, $value) {
    static $i = 0;
    // Counter guarantees that added fields come at the end of the query,
    // in order.
    $this->fields[$i++] = array('#name' => $field, '#value' => trim($value));
  }

  public function get_fields() {
    return $this->fields;
  }

  public function remove_field($name, $value = NULL) {
    // We can only remove named fields.
    if (empty($name)) {
      return;
    }
    if (!isset($value)) {
      foreach ($this->fields as $pos => $values) {
        if ($values['#name'] == $name) {
          unset($this->fields[$pos]);
        }
      }
    }
    else {
      foreach ($this->fields as $pos => $values) {
        if ($values['#name'] == $name && $values['#value'] == $value) {
          unset($this->fields[$pos]);
        }
      }
    }
  }

  public function has_field($name, $value) {
    foreach ($this->fields as $pos => $values) {
      if (!empty($values['#name']) && !empty($values['#value']) && $values['#name'] == $name && $values['#value'] == $value) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Handle aliases for field to make nicer URLs
   *
   * @param $field_map
   *   An array keyed with real Solr index field names, with value being the alias.
   */
  function add_field_aliases($field_map) {
    $this->field_map = array_merge($this->field_map, $field_map);
    // We have to re-parse the filters.
    $this->parse_filters();
  }

  function get_field_aliases() {
    return $this->field_map;
  }

  function clear_field_aliases() {
    $this->field_map = array();
    // We have to re-parse the filters.
    $this->parse_filters();
  }

  /**
   * A subquery is another instance of a Solr_Base_Query that should be joined
   * to the query. The operator determines whether it will be joined with AND or
   * OR.
   *
   * @param $query
   *   An instance of Solr_Base_Query.
   *
   * @param $operator
   *   'AND' or 'OR'
   */
  function add_subquery(Solr_Base_Query $query, $fq_operator = 'OR', $q_operator = 'AND') {
    $this->subqueries[$query->id] = array('#query' => $query, '#fq_operator' => $fq_operator, '#q_operator' => $q_operator);
  }

  function remove_subquery(Solr_Base_Query $query) {
    unset($this->subqueries[$query->id]);
  }

  public function remove_subqueries() {
    $this->subqueries = array();
  }

  public function set_solrsort($sortstring) {
    $this->solrsort = trim($sortstring);
  }
  /**
   * Return filters and sort in a form suitable for a query param to url().
   */
  public function get_url_querystring() {
    $querystring = '';
    if ($fq = $this->rebuild_fq(TRUE)) {
      $querystring = 'filters='. implode(' ', $fq);
    }
    if ($this->solrsort) {
      $querystring .= ($querystring ? '&' : '') .'solrsort='. $this->solrsort;
    }
    return $querystring;
  }

  public function get_fq() {
    return $this->rebuild_fq();
  }

  /**
   * A function to get just the keyword components of the query,
   * omitting any field:value portions.
   */
  public function get_query_basic() {
    return $this->rebuild_query();
  }

  /**
   * Build additional breadcrumb elements relative to $base.
   */
  public function get_breadcrumb($base = '') {
    $progressive_crumb = array();

    $search_keys = $this->get_query_basic();
    if ($search_keys) {
      $breadcrumb[] = l($search_keys, $base);
    }

    foreach ($this->fields as $field) {
      $name = $field['#name'];
      // Look for a field alias.
      if (isset($this->field_map[$name])) {
        $field['#name'] = $this->field_map[$name];
      }
      $progressive_crumb[] = Solr_Base_Query::make_field($field);
      $options = array('query' => 'filters=' . implode(' ', $progressive_crumb));
      if ($themed = theme("apachesolr_breadcrumb_{$name}", $field['#value'])) {
        $breadcrumb[] = l($themed, $base, $options);
      }
      else {
        $breadcrumb[] = l($field['#name'], $base, $options);
      }
    }
    // The last breadcrumb is the current page, so it shouldn't be a link.
    $last = count($breadcrumb) - 1;
    $breadcrumb[$last] = strip_tags($breadcrumb[$last]);

    return $breadcrumb;
  }

  /**
   * Parse the filter string in $this->filters into $this->fields.
   *
   * Builds an array of field name/value pairs.
   */
  protected function parse_filters() {
    $this->fields = array();
    $filters = $this->filters;

    // Gets information about the fields already in solr index.
    $index_fields = $this->solr->getFields();

    foreach ((array) $index_fields as $name => $data) {
      // Look for a field alias.
      $alias = isset($this->field_map[$name]) ? $this->field_map[$name] : $name;
      // Get the values for $name
      $extracted = Solr_Base_Query::field_extract($filters, $alias);
      if (count($extracted['values'])) {
        foreach ($extracted['values'] as $index => $value) {
          $pos = strpos($this->filters, $extracted['queries'][$index]);
          // $solr_keys and $solr_crumbs are keyed on $pos so that query order
          // is maintained. This is important for breadcrumbs.
          $this->fields[$pos] = array('#name' => $name, '#value' => trim($value));
        }
      }
    }
    // Even though the array has the right keys they are likely in the wrong
    // order. ksort() sorts the array by key while maintaining the key.
    ksort($this->fields);
  }

  /**
   * Builds a set of filter queries from $this->fields and all subqueries.
   *
   * Returns an array of strings that can be combined into
   * a URL query parameter or passed to Solr as fq paramters.
   */
  protected function rebuild_fq($aliases = FALSE) {
    $fields = array();
    foreach ($this->fields as $pos => $field) {
      // Look for a field alias.
      if ($aliases && isset($this->field_map[$field['#name']])) {
        $field['#name'] = $this->field_map[$field['#name']];
      }
      $fq[] = Solr_Base_Query::make_field($field);
    }
    foreach ($this->subqueries as $id => $data) {
      $subfq = $data['#query']->rebuild_fq($aliases);
      if ($subfq) {
        $operator = $data['#fq_operator'];
        $fq[] = "(" . implode(" {$operator} ", $subfq) .")";
      }
    }
    return $fq;
  }

  protected function rebuild_query() {
    $query = $this->querypath;
    foreach ($this->subqueries as $id => $data) {
      $operator = $data['#q_operator'];
      $subquery = $data['#query']->get_query_basic();
      if ($subquery) {
        $query .= " {$operator} ({$subquery})";
      }
    }
    return $query;
  }
}
