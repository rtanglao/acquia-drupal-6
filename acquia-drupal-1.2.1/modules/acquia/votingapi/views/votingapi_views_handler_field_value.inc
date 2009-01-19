<?php
// $Id: votingapi_views_handler_field_value.inc,v 1.1.2.2 2008/10/01 15:47:47 eaton Exp $

/**
 * @file
 * Provide a views handlers for votingapi data fields.
 */

class votingapi_views_handler_field_value extends views_handler_field_numeric {
  function option_definition() {
    $options = parent::option_definition();
    $options['appearance'] = '';
    return $options;
  }

  function options_form(&$form, &$form_state) {
    parent::options_form($form, $form_state);

    $appearances = module_invoke_all('votingapi_views_formatters', $this);
    $options = array('' => t('Default appearance'));
    $options += $appearances;

    if (count($options) > 1) {
      $form['appearance'] = array(
        '#type' => 'select',
        '#title' => t('Appearance'),
        '#options' => $options,
        '#default_value' => $this->options['appearance'],
        '#weight' => -5,
      );
    }
  }

  function render($values) {
    $value = $values->{$this->field_alias};
    $function = $this->options['appearance'];
    if (!empty($function) && function_exists($function)) {
      return $function($value, $this, $values);
    }
    else {
      return parent::render($values);
    }
  }
}
