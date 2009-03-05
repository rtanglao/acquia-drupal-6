<?php
// $Id: apachesolr.admin.inc,v 1.1.2.10 2009/02/20 01:38:26 pwolanin Exp $

/**
 * @file
 *   Administrative pages for the Apache Solr framework.
 */

function apachesolr_settings() {
  $form = array();

  //perform a check to ensure the server is there
  $requirements = apachesolr_requirements('runtime');
  $status = $requirements['apachesolr']['severity'] == 2 ? 'error' : 'status';
  drupal_set_message($requirements['apachesolr']['value'], $status);

  $form['apachesolr_host'] = array(
    '#type' => 'textfield',
    '#title' => t('Solr host name'),
    '#default_value' => variable_get('apachesolr_host', 'localhost'),
    '#description' => t('Host name of your Solr server, e.g. <code>localhost</code> or <code>example.com</code>.'),
  );
  $form['apachesolr_port'] = array(
    '#type' => 'textfield',
    '#title' => t('Solr port'),
    '#default_value' => variable_get('apachesolr_port', '8983'),
    '#description' => t('Port on which the Solr server listens. The Jetty example server is 8983, while Tomcat is 8080 by default.'),
  );
  $form['apachesolr_path'] = array(
    '#type' => 'textfield',
    '#title' => t('Solr path'),
    '#default_value' => variable_get('apachesolr_path', '/solr'),
    '#description' => t('Path that identifies the Solr request handler to be used.'),
  );

  $numbers = drupal_map_assoc(array(10, 20, 50, 100));
  $form['apachesolr_cron_limit'] = array(
    '#type' => 'select',
    '#title' => t('Number of items to index per cron run'),
    '#default_value' => variable_get('apachesolr_cron_limit', 50),
    '#options' => $numbers,
    '#description' => t('The maximum number of items indexed in each pass of a <a href="@cron">cron maintenance task</a>. If necessary, reduce the number of items to prevent timeouts and memory errors while indexing.', array('@cron' => url('admin/reports/status')))
  );

  $options = drupal_map_assoc(array(5, 10, 15, 20, 25, 30, 40, 50, 60, 70, 80, 90, 100));
  $form['apachesolr_rows'] = array(
    '#type' => 'select',
    '#title' => t('Results per page'),
    '#default_value' => variable_get('apachesolr_rows', 10),
    '#options' => $options,
    '#description' => t('The number of results that will be shown per page.'),
  );
  $form['apachesolr_failure'] = array(
    '#type' => 'select',
    '#title' => t('On failure'),
    '#options' => array('show_error' => t('Show error message'),
      'show_drupal_results' => t('Show core Drupal results'),
      'show_no_results' => t('Show no results')
    ),
    '#default_value' => variable_get('apachesolr_failure', 'show_error'),
    '#description' => t('What to display if Apache Solr search is not available.'),
  );
  return system_settings_form($form);
}

/**
 * Gets information about the fields already in solr index.
 */
function apachesolr_index_page() {
  try {
    $solr = apachesolr_get_solr();
    // TODO: only clear this every page view if we are running
    // multi-site.
    $solr->clearCache();
    $data = $solr->getLuke();
  }
  catch (Exception $e) {
    watchdog('apachesolr', $e->getMessage());
    drupal_set_message($e->getMessage(), "warning");
    $data->fields = array();
  }

  $output  = '';
  if (isset($data->index->numDocs)) {
    $pending_docs = '';
    try {
      $stats = $solr->getStats(TRUE);
      $docsPendingXpath = $stats->xpath('//stat[@name="docsPending"]');
      $pending_docs = trim($docsPendingXpath[0]);
    }
    catch (Exception $e) {
      watchdog('apachesolr', $e->getMessage());
    }
    $pending_msg = $pending_docs ? t('(@pending_docs sent but not yet processed)', array('@pending_docs' => $pending_docs)) : '';
    $output .= '<p>' . t('Number of documents in index: @num !pending', array('@num' => $data->index->numDocs, '!pending' => $pending_msg)) . "</p>\n";
  }


  $limit = variable_get('apachesolr_luke_limit', 20000);
  if (isset($data->index->numDocs) && $data->index->numDocs > $limit) {
    $output .= '<p>' . t('You have more than @limit documents, so term frequencies are being omitted for performance reasons.', array('@limit' => $limit)) . "</p>\n";
    $not_found = t('<em>Omitted</em>');
  }
  elseif (isset($data->index->numDocs)) {
    $not_found = t('Not indexed');
    try {
      $solr = apachesolr_get_solr();
      // Note: we use 2 since 1 fails on Ubuntu Hardy.
      $data = $solr->getLuke(2);
      $output .= '<p>' . t('Number of terms in index: @num', array('@num' => $data->index->numTerms)) . "</p>\n";
    }
    catch (Exception $e) {
      watchdog('apachesolr', $e->getMessage());
      $data->fields = array();
    }
  }

  $fields = (array)$data->fields;  
  if ($fields) {
    $output .= '<p>' . t('Number of fields in index: @num', array('@num' => count($fields))) . "</p>\n";
    $rows = array();
    foreach ($fields as $name => $field) {
      // TODO: try to map the name to something more meaningful.
      $rows[$name] = array($name, $field->type, isset($field->distinct) ? $field->distinct : $not_found);
    }
    ksort($rows);
    // Display the table of Field names, Index Types, and term counts.
    $output .= theme('table', array(t('Field name'), t('Index type'), t('Distinct terms')), $rows);
  }
  else {
    $output .= '<p>' . t('No data on indexed fields.') . "</p>\n";
  }

  // Display the Delete Index form.
  $output .= drupal_get_form('apachesolr_delete_index_form');

  return $output;
}

/**
 * Indicates what order the specified facets should be listed in.  This function is used in a usort
 * invocation.
 * @param $a
 *   The first facet.
 * @param $b
 *   The second facet.
 * @return
 *   A signed integer that indicates which of the specified facets should come first.
 */
function _apachesolr_sort_facets($a, $b) {
  return strcasecmp($a['info'], $b['info']);
}

/**
 * This is the submit handler for the active facets form.
 *
 * The form values for each module are array filtereed to remove non-enabled items and
 * stored in the variable table with the name 'apachesolr_enabled_facets'.
 *
 * @see apachesolr_enabled_facets_form()
 */
function apachesolr_enabled_facets_form_submit($form, &$form_state) {
  $enabled = array();
  foreach ($form_state['values']['apachesolr_enabled_facets'] as $module => $facets) {
    $enabled[$module] = array_filter($facets);
  }
  variable_set('apachesolr_enabled_facets', $enabled);
  drupal_set_message($form_state['values']['submit_message']);
}

/**
 * Creates the form that allows the user to select which facets will be enabled.
 *
 * Only enabled facets are sent to solr.  Fewer enabled facets can reduce the
 * load on the search server.  Blocks are only offered for enabled facets, so
 * this also reduces the clutter on the blocks admin page.
 */
function apachesolr_enabled_facets_form() {
  $facets = array();
  $module_facets = array();
  $module_list = array();
  foreach (module_implements('apachesolr_facets') as $module) {
    $module_facets[$module] = module_invoke($module, 'apachesolr_facets');
    uasort($module_facets[$module], '_apachesolr_sort_facets');
    $module_list[$module] = $module;
  }

  $enabled_facets = apachesolr_get_enabled_facets();
  $form = array();
  $form['apachesolr_enabled_facets']['help'] = array (
    '#type' => 'item',
    '#value' => t('You can use this screen to select which search filter blocks should be created by enabling the corresponding filters on this page. For performance reasons, you should only enable filters that you intend to have available to users on the search page.  After selecting which filter blocks to create, you will be sent to the blocks page where you can choose which of those blocks should be enabled when your users search by placing each block in a region.'),
  );
  if ($module_list) {
    $placeholders = implode(', ', array_fill(0, count($module_list), "'%s'"));
    $result = db_query("SELECT name, info FROM {system} WHERE name IN (". $placeholders .") AND type = 'module'", $module_list);
    while ($item = db_fetch_array($result)) {
      $module_list[$item['name']] = unserialize($item['info']);
    }
  }
  foreach($module_facets as $module => $facets) {
    $form['apachesolr_enabled_facets'][$module] = array(
      '#type' => 'fieldset',
      '#title' => check_plain($module_list[$module]['name']),
      '#collapsible' => TRUE,
      '#collapsed' => FALSE,
    );
    // We must use module + delta as the keys since that combination is
    // guaranteed to be unique.  A single module could, for example, have
    // two different blocks that expose different faceting on the same
    // field in the index.
    foreach($facets as $delta => $data) {
      $form['apachesolr_enabled_facets'][$module][$delta] = array(
        '#type' => 'checkbox',
        '#title' => $data['info'],
        '#return_value' => $data['facet_field'],
        '#default_value' => isset($enabled_facets[$module][$delta]) ? $data['facet_field'] : 0,
      );
    }
  }

  $has_facets = (bool)$module_facets;

  $form['submit'] = array(
    '#type' => 'submit',
    '#value' => t('Save'),
    '#access' => $has_facets,
  );
  $form['no-facets-message'] = array(
    '#value' => t('<em>No filters are available from your currently enabled modules</em>'),
    '#access' => !$has_facets,
  );

  $form['#tree'] = TRUE;
  $form['submit_message'] = array(
    '#type' => 'value',
    '#value' => t('The Apache Solr filters settings were changed.  To arrange the blocks for your enabled filters, visit the <a href="@url">blocks administration page</a>.', array('@url' => url('admin/build/block'))),
  );

  return $form;
}

/**
 * Create a form for deleting the contents of the Solr index.
 */
function apachesolr_delete_index_form() {
  $form = array();
  $form['markup'] = array(
    '#prefix' => '<h3>',
    '#value' => t('Solr Index'),
    '#suffix' => '</h3>',
  );
  $form['reindex'] = array(
    '#type' => 'submit',
    '#value' => t('Re-index all content'),
    '#submit' => array('apachesolr_clear_index'),
  );
  $form['reindex-desc'] = array(
    '#type' => 'item',
    '#description' => t('Re-indexing will add all content to the index again (overwriting the index), but existing content in the index will remain searchable.'),
  );
  $form['submit'] = array(
    '#type' => 'submit',
    '#value' => t('Delete the index'),
    '#validate' => array('apachesolr_delete_index_validate'),
    '#submit' => array('apachesolr_delete_index'),
  );
  $form['delete_index'] = array(
    '#type' => 'checkbox',
    '#title' => t('Confirm index deletion'),
    '#description' => t('Deletes all of the documents in the Solr index. This is rarely necessary unless your index is corrupt or you have installed a new schema.xml.'),
    '#default_value' => NULL,
  );
  return $form;
}

/**
 * Submit function for the 'Re-index all content' button.
 *
 * @see apachesolr_delete_index_form()
 */
function apachesolr_clear_index($form, &$form_state) {
  apachesolr_clear_last_index();
}

/**
 * Validate function for the 'Delete the index' button.
 *
 * @see apachesolr_delete_index_form()
 */
function apachesolr_delete_index_validate($form, &$form_state) {
  if (!$form_state['values']['delete_index']) {
    form_set_error('delete_index', t('If you want to delete the Solr index, you must check the confirmation box.'));
  }
}

/**
 * Submit function for the 'Delete the index' button.
 *
 * @see apachesolr_delete_index_form()
 */
function apachesolr_delete_index() {
  try {
    // Instantiate a new Solr object.
    $solr = apachesolr_get_solr();
    $query = '*:*';
    // Allow other modules to modify the delete query.
    // For example, use the site hash so that you only delete this site's
    // content:  $query = 'hash:' . apachesolr_site_hash()
    drupal_alter('apachesolr_delete_index', $query);
    $solr->deleteByQuery($query);
    $solr->commit();
    apachesolr_clear_last_index();
    // This form can't be seen by anyone without 'administer site configuration'
    // permission, so no need to check perms before displaying a run-cron link.
    drupal_set_message(t('The Solr content index has been erased. You must now !run_cron until your entire site has been re-indexed.', array('!run_cron' => l(t('run cron'), 'admin/reports/status/run-cron', array('query' => array('destination' => 'admin/settings/apachesolr/index'))))));
  }
  catch (Exception $e) {
    watchdog('Apache Solr', $e->getMessage(), array(), WATCHDOG_ERROR);
  }
}

