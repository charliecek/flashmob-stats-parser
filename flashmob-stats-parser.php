<?php
/**
 * Plugin Name: International Flashmob Stats Parser
 * Description: Parses Stats From <a href="https://flashmob.dileque.si" target="_blank">flashmob.dileque.si</a> And Provides Stats Shortcodes
 * Author: charliecek
 * Author URI: http://charliecek.eu/
 * Version: 1.1.0
 */

class FSP{
  private $aStats = array();
  private $iYear = 0;
  private $aFields = array();
  private $strDefaultField = "countries";
  private $strOptionsPageSlug = "fsp-options";
  private $strOptionKey = "fsp-options";
  private $strStatsKey = "fsp-stats";
  private $aMonths = array();
  private $bIsSeason = false;
  
  public function __construct() {
    add_action( 'fsp_cron', array( $this, 'fsp_cron' ) );
    add_shortcode( 'fsp-stats-allstats', array( $this, 'allstats' ));
    add_shortcode( 'fsp-stats-countries', array( $this, 'countries' ));
    add_shortcode( 'fsp-stats-cities', array( $this, 'cities' ));
    add_shortcode( 'fsp-stats-locations', array( $this, 'locations' ));
    add_shortcode( 'fsp-stats-update_time', array( $this, 'update_time' ));
    add_shortcode( 'fsp-counter', array( $this, 'counter' ));
    
    add_action( 'admin_menu', array( $this, "action__add_options_page" ) );

    $this->aFields = array( $this->strDefaultField, "cities", "locations", "update-timestamp" );
    
    $this->aOptions = get_option( $this->strOptionKey, array() );
    $this->aStats = get_option( $this->strStatsKey , array() );

    $this->iYear = $this->get_current_year();
    $this->bIsSeason = $this->isSeason();
    
    
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
    
    $bEnableCron = (isset($aPostedOptions['fsp_enable_cron']) && $aPostedOptions['fsp_enable_cron'] === true) && $this->bIsSeason;

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
      __( "International Flashmob Stats Parser", "fsp" ),
      __( "International Flashmob Stats Parser", "fsp" ),
      "manage_options",
      $this->strOptionsPageSlug,
      array( $this, "options_page" )
    );
  }

  public function options_page() {
    echo "<h1>" . __("International Flashmob Stats Parser Settings", "fsp" ) . "</h1>";

    if (isset($_POST['save-fsp-options'])) {
      $this->save_option_page_options($_POST);
    }
    
    // echo "<pre>" .var_export($this->aOptions, true). "</pre>";
    // echo "<pre>" .var_export($this->iYear, true). "</pre>";
    // echo "<pre>" .var_export($this->bIsSeason, true). "</pre>";
    if ($this->aOptions['bEnableCron'] === true) {
      $strEnableCronChecked = 'checked="checked"';
    } else {
      $strEnableCronChecked = '';
    }
    
    $strOptionsMonthsStart = "";
    $strOptionsMonthsEnd = "";
    $iSeasonStartMonth = $this->aOptions["iSeasonStartMonth"];
    $iSeasonEndMonth = $this->aOptions["iSeasonEndMonth"];
    for ($i = 1; $i <= 12; $i++) {
      if ($iSeasonStartMonth == $i) {
        $strSelectedStart = 'selected="selected"';
      } else {
        $strSelectedStart = '';
      }
      if ($iSeasonEndMonth == $i) {
        $strSelectedEnd = 'selected="selected"';
      } else {
        $strSelectedEnd = '';
      }
      $strMonthName = __( date('F', mktime(0, 0, 0, $i, 1, date('Y'))), 'fsp' );
      $strOptionsMonthsStart .= '<option value="'.$i.'" '.$strSelectedStart.'>'.$strMonthName.'</option>';
      $strOptionsMonthsEnd .= '<option value="'.$i.'" '.$strSelectedEnd.'>'.$strMonthName.'</option>';
    }
    
    $strSeasonPlaceholder = ($this->bIsSeason) ? '%%inSeason%% %%labelCurrentSeason%%' : '%%outOfSeason%%';
    echo str_replace(
      array(
        '%%enableCronChecked%%', "%%enableCronCheckedLabel%%", "%%seasonStartLabel%%", "%%seasonEndLabel%%", "%%saveButtonLabel%%",
        "%%optionsMothsStart%%", "%%optionsMothsEnd%%", "%%seasonInfo%%",
        "%%inSeason%%", "%%outOfSeason%%", "%%labelCurrentSeason%%", ),
      array(
        $strEnableCronChecked, __( "Enable Cron?", 'fsp' ), __( "Start of International Flashmob Season", 'fsp' ), __( "End of International Flashmob Season", 'fsp' ), __( "Save", 'fsp' ),
        $strOptionsMonthsStart, $strOptionsMonthsEnd, __( "Info: Outside of Season, the cron is disabled. End of Season Month's year is taken as current season.", 'fsp' ),
        __( "We are IN season, currently.", 'fsp' ), __( "We are NOT in season, currently.", 'fsp' ), __( "Current season: ", 'fsp' ). $this->iYear .".", ),
      '
        <form action="" method="post">
          <table style="width: 100%">
            <tr style="width: 98%; padding:  5px 1%;">
              <th style="width: 47%; padding: 0 1%; text-align: right;"><label for="fsp_enable_cron">%%enableCronCheckedLabel%%</label></th>
              <td>
                <input id="fsp_enable_cron" name="fsp_enable_cron" type="checkbox" %%enableCronChecked%% value="1"/>
              </td>
            </tr>
            <tr>
              <th style="width: 47%; padding: 0 1%; text-align: right;"><label for="fsp_season_start">%%seasonStartLabel%%</label></th>
              <td><select id="fsp_season_start" name="fsp_season_start">%%optionsMothsStart%%</select></td>
            </tr>
            <tr>
              <th style="width: 47%; padding: 0 1%; text-align: right;"><label for="fsp_season_end">%%seasonEndLabel%%</label></th>
              <td><select id="fsp_season_end" name="fsp_season_end">%%optionsMothsEnd%%</select></td>
            </tr>
            <tr>
              <th colspan="2">
                <span style="font-size: smaller;">%%seasonInfo%%</span>
              </th>
            </tr>
            <tr>
              <th colspan="2">
                <span style="font-size: smaller;">'.$strSeasonPlaceholder.'</span>
              </th>
            </tr>
          </table>
          
          <span style="">
            <input id="save-fsp-options-bottom" class="button button-primary left button-large" name="save-fsp-options" type="submit" value="%%saveButtonLabel%%"  />
          </span>
          
        </form>
      '
    );
  }
  
  private function save_option_page_options( $aPostedOptions ) {
    // echo "<pre>" .var_export($aPostedOptions, true). "</pre>";
    $aOptionsToSave = array(
      'bEnableCron'     => (isset($aPostedOptions['fsp_enable_cron']) && $aPostedOptions['fsp_enable_cron'] === '1'),
      'iSeasonEndMonth' => isset($aPostedOptions['fsp_season_end']) ? intval($aPostedOptions['fsp_season_end']) : 0,
      'iSeasonStartMonth' => isset($aPostedOptions['fsp_season_start']) ? intval($aPostedOptions['fsp_season_start']) : 0,
    );
    update_option( $this->strOptionKey, $aOptionsToSave, true );
    $this->aOptions = $aOptionsToSave;

    $this->iYear = $this->get_current_year();
    $this->bIsSeason = $this->isSeason();
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
  
  private function get_current_year() {
    if (!isset($this->aOptions['iSeasonEndMonth'])) {
      return intval( date( "Y" ) );
    }
    
    $iYear = intval( date( "Y", mktime(2, 0, 0, date("n") - $this->aOptions['iSeasonEndMonth'], 1, date('Y'))) ) + 1;
    return $iYear;
    
  }
  
  private function isSeason() {
    if (!isset($this->aOptions["iSeasonStartMonth"]) || !isset($this->aOptions["iSeasonEndMonth"])) {
      return true;
    }
    $iSeasonStartMonth = $this->aOptions["iSeasonStartMonth"];
    $iSeasonEndMonth = $this->aOptions["iSeasonEndMonth"];
    $iMonthNow = intval(date( 'n' ));
    
    if ($iSeasonStartMonth == $iSeasonEndMonth ) {
      return true;
    } elseif ($iSeasonStartMonth < $iSeasonEndMonth && ($iSeasonStartMonth > $iMonthNow || $iSeasonEndMonth < $iMonthNow)) {
      // We are NOT BETWEEN start and end // 
      return false;
    } elseif ($iSeasonStartMonth > $iSeasonEndMonth && ($iSeasonEndMonth < $iMonthNow && $iSeasonStartMonth > $iMonthNow)) {
      // We are BETWEEN end and start //
      return false;
    }
    // var_dump($iSeasonStartMonth, $iSeasonEndMonth, $iMonthNow);
    return true;
  }
}

$FSP = new FSP();
register_activation_hook(__FILE__, array('FSP', 'fsp_activate'));
register_deactivation_hook(__FILE__, array('FSP', 'fsp_deactivate'));