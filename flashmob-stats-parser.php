<?php
/**
 * Plugin Name: Flashmob Stats Parser
 * Description: Parses stats from <a href="https://flashmob.dileque.si" target="_blank">flashmob.dileque.si</a> And Provides Stats Shortcodes
 * Author: charliecek
 * Author URI: http://charliecek.eu/
 * Version: 1.0.0
 */

class FSP{
  private $aStats = array();
  private $iYear = 0;
  private $aFields = array();
  private $strDefaultField = "countries";
  private $strOptionsPageSlug = "fsp-options";
  private $strOptionKey = "fsp-options";
  private $strStatsKey = "fsp-stats";
  
  public function __construct() {
    add_action( 'fsp_cron', array( $this, 'fsp_cron' ) );
    add_shortcode( 'fsp-stats-allstats', array( $this, 'allstats' ));
    add_shortcode( 'fsp-stats-countries', array( $this, 'countries' ));
    add_shortcode( 'fsp-stats-cities', array( $this, 'cities' ));
    add_shortcode( 'fsp-stats-locations', array( $this, 'locations' ));
    add_shortcode( 'fsp-stats-update_time', array( $this, 'update_time' ));
    add_shortcode( 'fsp-counter', array( $this, 'counter' ));
    
    add_action( 'admin_menu', array( $this, "action__add_options_page" ) );

    $this->iYear = intval( date( "Y" ) );
    
    $this->aOptions = get_option( $this->strOptionKey, array() );
    $this->aStats = get_option( $this->strStatsKey , array() );
    $this->aFields = array( $this->strDefaultField, "cities", "locations", "update-timestamp" );
    
    if (empty($this->aStats)) {
      $this->aStats = $this->fsp_cron( true );
    } else if ($this->aStats[$this->strDefaultField] ) {
      // Options need to be upgraded to yearly //
      $aStats = $this->aStats;
      $this->aStats = array(
        $this->iYear => $aStats
      );
      update_option( $this->strStatsKey, $this->aStats, true );
    }
    if (!isset($this->aStats[$this->iYear])) {
      $this->aStats = $this->fsp_cron( true );
    }
    
    if (!isset($this->aStats[2017])) {
      $this->aStats[2017] = array(
        'countries'         => 57,
        'cities'            => 207,
        'locations'         => 230,
        'update-timestamp'  => strtotime( "19 December 2017" ),
      );
      update_option( $this->strStatsKey, $this->aStats, true );
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
  public function fsp_cron( $bFixValues = false ) {
    // return; // turned off 2017/04/09 //
    // return; // turned on  2017/12/26 //
    
    $bEnableCron = (isset($aPostedOptions['fsp_enable_cron']) && $aPostedOptions['fsp_enable_cron'] === true);
    if (!$bEnableCron) {
      if ($bFixValues) {
        // If cron is not enabled and fsp_cron was called with $bFixValues == true, set empty defaults //
        if (!isset($this->aStats) || empty($this->aStats)) {
          $this->aStats = array(
            $this->iYear => array()
          );
        } elseif (!isset($this->aStats[$this->iYear])) {
          $this->aStats[$this->iYear] = array();
        }
        update_option( $this->strStatsKey, $this->aStats, true );
        return $this->aStats;
      }
      return;
    }

    $aStatsCurrent = array();
    
    $html = file_get_contents('https://flashmob.dileque.si');
    
    $dom = new DOMDocument();
    $internalErrors = libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_use_internal_errors($internalErrors);
    
    $xpath = new \DomXPath($dom);

    $aStatsCurrent["countries"] = trim($xpath->evaluate("string(//div[@id='stats']//div[@class='statscountries']//span[@class='number'])"));
    $aStatsCurrent["cities"] = trim($xpath->evaluate("string(//div[@id='stats']//div[@class='statscities']//span[@class='number'])"));
    $aStatsCurrent["locations"] = trim($xpath->evaluate("string(//div[@id='stats']//div[@class='statslocations']//span[@class='number'])"));
    $aStatsCurrent["update-timestamp"] = time();
    
    $aStats = $this->aStats;
    $aStats[$this->iYear] = $aStatsCurrent;
    
    update_option( $this->strStatsKey, $aStats, true );
    
    if ($bFixValues) {
      return get_option($this->strStatsKey, array());
    }
  }

  public function action__add_options_page() {
    add_options_page(
      __( "Flashmob Stats Parser", "fsp" ),
      __( "Flashmob Stats Parser", "fsp" ),
      "manage_options",
      $this->strOptionsPageSlug,
      array( $this, "options_page" )
    );
  }

  public function options_page() {
    echo "<h1>" . __("Flashmob Stats Parser Settings", "fsp" ) . "</h1>";

    if (isset($_POST['save-fsp-options'])) {
      $this->save_option_page_options($_POST);
    }
    
    // echo "<pre>" .var_export($this->aOptions, true). "</pre>";
    if ($this->aOptions['bEnableCron'] === true) {
      $strEnableCronChecked = 'checked="checked"';
    } else {
      $strEnableCronChecked = '';
    }
    
    echo str_replace(
      array( '%%enableCronChecked%%', "%%enableCronCheckedLabel%%", "%%saveButtonLabel%%" ),
      array( $strEnableCronChecked, __( "Enable Cron?", 'fsp' ), __( "Save", 'fsp' ) ),
      '
        <form action="" method="post">
          <input id="fsp_enable_cron" name="fsp_enable_cron" type="checkbox" %%enableCronChecked%% value="1"/>
          <label for="fsp_enable_cron">%%enableCronCheckedLabel%%</label><br/>
          <input id="save-fsp-options-bottom" class="button button-primary right button-large" name="save-fsp-options" type="submit" value="%%saveButtonLabel%%" />
        </form>
      '
    );
  }
  
  private function save_option_page_options( $aPostedOptions ) {
    // echo "<pre>" .var_export($aPostedOptions, true). "</pre>";
    $aOptionsToSave = array(
      'bEnableCron' => (isset($aPostedOptions['fsp_enable_cron']) && $aPostedOptions['fsp_enable_cron'] === '1'),
    );
    update_option( $this->strOptionKey, $aOptionsToSave, true );
    $this->aOptions = $aOptionsToSave;
  }

// No need - yet //
//   public function getStats() {
//     return $this->aStats;
//   }
  
  public function allstats( $aAttributes = array() ) {
    return "<pre>" .var_export($this->aStats, true). "</pre>";
  }
  public function countries( $aAttributes = array() ) {
    return $this->get_stats_field( $aAttributes, 'countries' );
  }
  public function cities( $aAttributes = array() ) {
    return $this->get_stats_field( $aAttributes, 'cities' );
  }
  public function locations( $aAttributes = array() ) {
    return $this->get_stats_field( $aAttributes, 'locations' );
  }
  public function update_time( $aAttributes = array() ) {
    $iUpdateTimestamp = $this->get_stats_field( $aAttributes, 'update-timestamp' );
    $strFormat = isset($aAttributes['format']) ? $aAttributes['format'] : 'j.n.Y G:i:s';
    return date( $strFormat, intval( $iUpdateTimestamp ));
  }
  
  public function counter( $aAttributes = array() ) {
    if (isset($aAttributes['what']) && in_array( $aAttributes['what'], $this->aFields )) {
      $strTargetField = $aAttributes['what'];
    } else {
      $strTargetField = $this->strDefaultField;
    }
    $strTarget = $this->get_stats_field( $aAttributes, $strTargetField );
    
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
  
  private function get_stats_field( $aAttributes, $strField, $mixDefault = 0 ) {
    $iYear = $this->get_year_attribute( $aAttributes );
    if (!isset($this->aStats[$iYear]) || !isset($this->aStats[$iYear][$strField])) {
      return $mixDefault;
    } else {
      return $this->aStats[$iYear][$strField];
    }
  }

  private function get_year_attribute( $aAttributes ) {
    $iYear = $this->iYear;
    if (isset($aAttributes['year']) && !empty($aAttributes['year'])) {
      $iAttrYear = intval($aAttributes['year']);
      if ($iAttrYear > 0) {
        $iYear = $iAttrYear;
      }
    }
    return $iYear;
  }
}

$FSP = new FSP();
register_activation_hook(__FILE__, array('FSP', 'fsp_activate'));
register_deactivation_hook(__FILE__, array('FSP', 'fsp_deactivate'));