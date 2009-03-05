<?php
// $Id$
include_once './' . drupal_get_path('module', 'apachesolr') . '/Drupal_Apache_Solr_Service.php';
include_once './' . drupal_get_path('module', 'acquia_agent') . '/acquia_agent_streams.inc';

/**
 * Starting point for the Solr API. Represents a Solr server resource and has
 * methods for pinging, adding, deleting, committing, optimizing and searching.
 *
 */
class Acquia_Search_Service extends Drupal_Apache_Solr_Service {

  protected function add_request_id(&$url) {
    $id = uniqid();
    if (!stristr($url,'?')) {
      $url .= "?";
    }
    $url .= '&request_id=' . $id;
  }
  /**
   * Central method for making a get operation against this Solr Server
   *
   * @see Drupal_Apache_Solr_Service::_sendRawGet()
   */
  protected function _sendRawGet($url, $timeout = FALSE) {
    $this->add_request_id($url);
    list($cookie, $nonce) = acquia_search_auth_cookie($url);
    $request_headers = array(
      'Cookie' => $cookie,
    );
    list ($data, $headers) = $this->_makeHttpRequest($url, 'GET', $request_headers, '', $timeout);
    $response = new Apache_Solr_Response($data, $headers, $this->_createDocuments, $this->_collapseSingleValueArrays);
    $hmac = acquia_search_extract_hmac($headers);
    if ($response->getHttpStatus() != 200) {
      throw new Exception('"' . $response->getHttpStatus() . '" Status: ' . $response->getHttpStatusMessage() . "\n<br />request ID: $id <br />" . $url, $response->getHttpStatus());
    }
    elseif (!acquia_search_valid_response($hmac, $nonce, $data)) {
      throw new Exception('Authentication of search content failed url: '. $url);
    }
    return $response;
  }

  /**
   * Central method for making a post operation against this Solr Server
   *
   * @see Drupal_Apache_Solr_Service::_sendRawGet()
   */
  protected function _sendRawPost($url, $rawPost, $timeout = FALSE, $contentType = 'text/xml; charset=UTF-8')  {
    $this->add_request_id($url);
    list($cookie, $nonce) = acquia_search_auth_cookie($url, $rawPost);
    $request_headers = array(
      'Content-Type' => $contentType,
      'Cookie' => $cookie
    );
    list ($data, $headers) = $this->_makeHttpRequest($url, 'POST', $request_headers, $rawPost, $timeout);
    $response = new Apache_Solr_Response($data, $headers, $this->_createDocuments, $this->_collapseSingleValueArrays);
    if ($response->getHttpStatus() != 200) {
      throw new Exception('"' . $response->getHttpStatus() . '" Status: ' . $response->getHttpStatusMessage() . "\n<br />request ID: $id <br />" . $url, $response->getHttpStatus());
    }
    return $response;
  }

  /**
   * Send an optimize command.
   *
   * We want to control the schedule of optimize commands ourselves,
   * so do a method override to make ->optimize() a no-op.
   *
   * @see Drupal_Apache_Solr_Service::optimize()
   */
  public function optimize($waitFlush = true, $waitSearcher = true, $timeout = 3600) {
    return TRUE;
  }

  protected function _makeHttpRequest($url, $method = 'GET', $headers = array(), $content = '', $timeout = FALSE) {
    // Set a response timeout
    if ($timeout) {
      $default_socket_timeout = ini_set('default_socket_timeout', $timeout);
    }

    $ctx = acquia_agent_stream_context_create($url, 'acquia_search');
    if (!$ctx) {
      throw new Exception(t("Could not create stream context"));
    }

    $result = acquia_agent_http_request($ctx, $url, $headers, $method, $content);

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

    if (!isset($result->error)) {
      $result->error = '';
    }

    if (!isset($responses[$result->code])) {
      $result->code = floor($result->code / 100) * 100;
    }

    $protocol = "HTTP/1.1";

    $headers[] = "{$protocol} {$result->code} {$responses[$result->code]}. {$result->error}";
    if (isset($result->headers)) {
      foreach ($result->headers as $name => $value) {
        $headers[] = "$name: $value";
      }
    }
    return array($result->data, $headers);
  }

}
