<?php
require_once 'SolrPhpClient/Apache/Solr/Service.php';

class Drupal_Apache_Solr_Service extends Apache_Solr_Service {

  protected $luke;
  protected $luke_cid;
  protected $stats;
  const LUKE_SERVLET = 'admin/luke';
  const STATS_SERVLET = 'admin/stats.jsp';

  /**
   * Sets $this->luke with the meta-data about the index from admin/luke.
   */
  protected function setLuke($num_terms = 0) {
    if (empty($this->luke[$num_terms])) {
      $url = $this->_constructUrl(self::LUKE_SERVLET, array('numTerms' => "$num_terms", 'wt' => self::SOLR_WRITER));
      $this->luke[$num_terms] = $this->_sendRawGet($url);
      cache_set($this->luke_cid, $this->luke);
    }
  }

  /**
   * Get just the field meta-data about the index.
   */
  public function getFields($num_terms = 0) {
    return $this->getLuke($num_terms)->fields;
  }

  /**
   * Get meta-data about the index.
   */
  public function getLuke($num_terms = 0) {
    if (!isset($this->luke[$num_terms])) {
      $this->setLuke($num_terms);
    }
    return $this->luke[$num_terms];
  }
  
  /**
   * Sets $this->stats with the information about the Solr Core form /admin/stats.jsp
   */
  protected function setStats() {
    if (empty($this->stats)) {
      $url = $this->_constructUrl(self::STATS_SERVLET);
      $this->stats = $this->_sendRawGet($url);
    }
  }
  
  /**
   * Get information about the Solr Core.
   */
  public function getStats($parsed = true) {
    if (!isset($this->stats)) {
      $this->setStats();
    }
    if ($parsed) {
      return simplexml_load_string($this->stats->getRawResponse());
    }
    return $this->stats;
  }

  /**
   * Clear cached Solr data.
   */
  public function clearCache() {
    cache_clear_all("apachesolr:luke:", 'cache', TRUE);
    $this->luke = array();
  }

  /**
   * Clear the cache whenever we commit changes.
   *
   * @see Apache_Solr_Service::commit()
   */
  public function commit($optimize = TRUE, $waitFlush = TRUE, $waitSearcher = TRUE, $timeout = 3600) {
    parent::commit($optimize, $waitFlush, $waitSearcher, $timeout);
    $this->clearCache();
  }

  /**
   * Construct the Full URLs for the three servlets we reference
   *
   * @see Apache_Solr_Service::_initUrls()
   */
  protected function _initUrls() {
    parent::_initUrls();
    $this->_lukeUrl = $this->_constructUrl(self::LUKE_SERVLET, array('numTerms' => '0', 'wt' => self::SOLR_WRITER));
  }

  /**
   * Put Luke meta-data from the cache into $this->luke when we instantiate.
   *
   * @see Apache_Solr_Service::__construct()
   */
  public function __construct($host = 'localhost', $port = 8180, $path = '/solr/') {
    parent::__construct($host, $port, $path);
    $this->luke_cid = "apachesolr:luke:$host:$port:$path";
    $cache = cache_get($this->luke_cid);
    if (isset($cache->data)) {
      $this->luke = $cache->data;
    }
  }

  /**
   * Central method for making a get operation against this Solr Server
   *
   * @see Apache_Solr_Service::_sendRawGet()
   */
  protected function _sendRawGet($url, $timeout = FALSE) {
    list ($data, $headers) = $this->_makeHttpRequest($url, 'GET', array(), '', $timeout);
    $response = new Apache_Solr_Response($data, $headers, $this->_createDocuments, $this->_collapseSingleValueArrays);
    if ($response->getHttpStatus() != 200) {
      throw new Exception('"' . $response->getHttpStatus() . '" Status: ' . $response->getHttpStatusMessage());
    }
    return $response;
  }

  /**
   * Central method for making a post operation against this Solr Server
   *
   * @see Apache_Solr_Service::_sendRawGet()
   */
  protected function _sendRawPost($url, $rawPost, $timeout = FALSE, $contentType = 'text/xml; charset=UTF-8') {
    $request_headers = array('Content-Type' => $contentType);
    list ($data, $headers) = $this->_makeHttpRequest($url, 'POST', $request_headers, $rawPost, $timeout);
    $response = new Apache_Solr_Response($data, $headers, $this->_createDocuments, $this->_collapseSingleValueArrays);
    if ($response->getHttpStatus() != 200) {
      throw new Exception('"' . $response->getHttpStatus() . '" Status: ' . $response->getHttpStatusMessage());
    }
    return $response;
  }

  protected function _makeHttpRequest($url, $method = 'GET', $headers = array(), $content = '', $timeout = FALSE) {
    // Set a response timeout
    if ($timeout) {
      $default_socket_timeout = ini_set('default_socket_timeout', $timeout);
    }
    $result = drupal_http_request($url, $headers, $method, $content);
    // Restore the response timeout
    if ($timeout) {
      ini_set('default_socket_timeout', $default_socket_timeout);
    }

    // This will no longer be needed after http://drupal.org/node/345591 is committed
    $responses = array(
      0 => 'Request failed',
      100 => 'Continue', 101 => 'Switching Protocols',
      200 => 'OK', 201 => 'Created', 202 => 'Accepted', 203 => 'Non-Authoritative Information', 204 => 'No Content', 205 => 'Reset Content', 206 => 'Partial Content',
      300 => 'Multiple Choices', 301 => 'Moved Permanently', 302 => 'Found', 303 => 'See Other', 304 => 'Not Modified', 305 => 'Use Proxy', 307 => 'Temporary Redirect',
      400 => 'Bad Request', 401 => 'Unauthorized', 402 => 'Payment Required', 403 => 'Forbidden', 404 => 'Not Found', 405 => 'Method Not Allowed', 406 => 'Not Acceptable', 407 => 'Proxy Authentication Required', 408 => 'Request Time-out', 409 => 'Conflict', 410 => 'Gone', 411 => 'Length Required', 412 => 'Precondition Failed', 413 => 'Request Entity Too Large', 414 => 'Request-URI Too Large', 415 => 'Unsupported Media Type', 416 => 'Requested range not satisfiable', 417 => 'Expectation Failed',
      500 => 'Internal Server Error', 501 => 'Not Implemented', 502 => 'Bad Gateway', 503 => 'Service Unavailable', 504 => 'Gateway Time-out', 505 => 'HTTP Version not supported'
    );

    if (!isset($result->code) || $result->code < 0) {
      $result->code = 0;
    }

    if (!isset($result->data)) {
      $result->data = '';
    }

    if (!isset($responses[$result->code])) {
      $result->code = floor($result->code / 100) * 100;
    }

    $protocol = "HTTP/1.1";
    $headers[] = "{$protocol} {$result->code} {$responses[$result->code]}";
    if (isset($result->headers)) {
      foreach ($result->headers as $name => $value) {
        $headers[] = "$name: $value";
      }
    }
    return array($result->data, $headers);
  }
}
