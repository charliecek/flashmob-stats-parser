<?php
/**
 * Plugin Name: Flashmob Stats Parser
 * Description: Parses stats from <a href="https://flashmob.dileque.si" target="_blank">flashmob.dileque.si</a>
 * Author: charliecek
 * Author URI: http://charliecek.eu/
 * Version: 0.1
 */

class FSP{
  private $aStats = array();
  
  public function __construct() {
    add_action( 'fsp_cron', array( $this, 'fsp_cron' ) );
    add_shortcode( 'fsp-stats-allstats', array( $this, 'allstats' ));
    add_shortcode( 'fsp-stats-countries', array( $this, 'countries' ));
    add_shortcode( 'fsp-stats-cities', array( $this, 'cities' ));
    add_shortcode( 'fsp-stats-locations', array( $this, 'locations' ));
    add_shortcode( 'fsp-stats-update_time', array( $this, 'update_time' ));
    add_shortcode( 'fsp-counter', array( $this, 'counter' ));
    
    $this->aStats = get_option('fsp-stats', array());
    if (empty($this->aStats)) {
      $this->aStats = $this->fsp_cron();
    }
  }
  
  public static function fsp_activate() {
    if ( !wp_next_scheduled( 'fsp_cron' ) ) {
      wp_schedule_event( time(), 'twicedaily', 'fsp_cron');
    }
  }
  public static function fsp_deactivate() {
    wp_clear_scheduled_hook('fsp_cron');
  }
  public function fsp_cron() {
    // turned off 2017/04/09 //
    // turned on  2017/12/26 //
    // TODO: add a turn on/off option //
    // TODO: make it per year (so there is archivation) //
//     return;
    $aStats = array();
    
    $html = file_get_contents('https://flashmob.dileque.si');
    
    $dom = new DOMDocument();
    $internalErrors = libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_use_internal_errors($internalErrors);
    
    $xpath = new \DomXPath($dom);

    $aStats["countries"] = trim($xpath->evaluate("string(//div[@id='stats']//div[@class='statscountries']//span[@class='number'])"));
    $aStats["cities"] = trim($xpath->evaluate("string(//div[@id='stats']//div[@class='statscities']//span[@class='number'])"));
    $aStats["locations"] = trim($xpath->evaluate("string(//div[@id='stats']//div[@class='statslocations']//span[@class='number'])"));
    $aStats["update-timestamp"] = time();
    
    update_option( 'fsp-stats', $aStats, true );
    
    return get_option('fsp-stats', array());
  }
  
  public function getStats() {
    return $this->aStats;
  }
  
  public function allstats( $aAttributes ) {
    return "<pre>" .var_export($this->getStats(), true). "</pre>";
  }
  public function countries( $aAttributes ) {
    return isset($this->aStats["countries"]) ? $this->aStats["countries"] : 0;
  }
  public function cities( $aAttributes ) {
    return isset($this->aStats["cities"]) ? $this->aStats["cities"] : 0;
  }
  public function locations( $aAttributes ) {
    return isset($this->aStats["locations"]) ? $this->aStats["locations"] : 0;
  }
  public function counter( $aAttributes ) {
    $strTarget = $this->aStats[$aAttributes['what']];
    $aAttributes['target'] = $strTarget;
    $aShortCodeAttributes = array();
    foreach ($aAttributes as $key => $val) {
      if ($key === 'what') continue;
      $aShortCodeAttributes[] = $key.'="'.$val.'"';
    }
    $strShortCodeAttributes = implode(' ', $aShortCodeAttributes);
    $strFullShortcode = '[us_counter '.$strShortCodeAttributes.']';
    return do_shortcode($strFullShortcode);
  }
  public function update_time( $aAttributes ) {
    $strFormat = isset($aAttributes['format']) ? $aAttributes['format'] : 'j.n.Y G:i:s';
    return date( $strFormat, intval($this->aStats['update-timestamp']));
  }
}

$FSP = new FSP();
register_activation_hook(__FILE__, array('FSP', 'fsp_activate'));
register_deactivation_hook(__FILE__, array('FSP', 'fsp_deactivate'));
