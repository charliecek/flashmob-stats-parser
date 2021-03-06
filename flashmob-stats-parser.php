<?php
/**
 * Plugin Name: International Flashmob Stats Parser
 * Description: Parses Stats From <a href="https://flashmob.dileque.si" target="_blank">flashmob.dileque.si</a> And Provides Stats Shortcodes
 * Author: charliecek
 * Author URI: http://charliecek.eu/
 * Version: 1.2.6
 */

class FSP{
  private $aStats = array();
  private $aShortcodes;
  private $iYear = 0;
  private $aFields = array();
  private $strDefaultField = "countries";
  private $strOptionsPageSlug = "fsp-options";
  private $strOptionKey = "fsp-options";
  private $aOptionDefaults = array();
  private $aOptions = array();
  private $strStatsKey = "fsp-stats";
  private $aMonths = array();
  private $bIsSeason = false;

  public function __construct() {
    $this->aFields = array( $this->strDefaultField, "cities", "locations", "update-timestamp" );

    add_action( 'fsp_cron', array( $this, 'fsp_cron' ) );
    add_action( 'fsp_cron_scheduler', array( $this, 'fsp_cron_scheduler' ) );

    $this->aShortcodes = array(
      'fsp-stats-allstats'    => 'allstats',
      'fsp-stats-countries'   => array(
        'method'          => 'countries',
        'example-args'    => 'year=2018',
        'info'            => ' <small>(arguments: year)</small>',
      ),
      'fsp-stats-cities'      => array(
        'method'          => 'cities',
        'info'            => ' <small>(arguments: year)</small>',
      ),
      'fsp-stats-locations'   => array(
        'method'          => 'locations',
        'info'            => ' <small>(arguments: year)</small>',
      ),
      'fsp-stats-update_time' => array(
        'method'          => 'update_time',
        'info'            => ' <small>(arguments: year)</small>',
      ),
      'fsp-counter'           => array(
        'method'          => 'counter',
        'example-args'    => 'what="countries" title="COUNTRIES"',
        'info'            => ' <small>(arguments: year, what [' . implode('|', $this->aFields) . "], title)</small>",
        'front-end-only'  => true,
      ),
    );

    foreach( $this->aShortcodes as $strShortcode => $mixMethod ) {
      if (is_array($mixMethod)) {
        add_shortcode( $strShortcode, array( $this, $mixMethod['method'] ));
      } else {
        add_shortcode( $strShortcode, array( $this, $mixMethod ));
      }
    }

    add_action( 'admin_menu', array( $this, "action__add_options_page" ), 11 );

    $this->aOptions = get_option( $this->strOptionKey, array() );
    $this->aStats = get_option( $this->strStatsKey , array() );

    $this->aOptionDefaults = array(
      'bEnableCron'       => false,
      'iSeasonEndMonth'   => 0,
      'iSeasonStartMonth' => 0,
      'iSeasonEndDay'     => 0,
      'iSeasonStartDay'   => 0,
      'iFlashmobDay'      => 0,
      'iFlashmobMonth'    => 0,
    );
    $bSave = false;
    foreach ($this->aOptionDefaults as $key => $val) {
      if (!isset($this->aOptions[$key])) {
        $this->aOptions[$key] = $val;
        $bSave = true;
      }
    }
    if ($bSave) {
      update_option( $this->strOptionKey, $this->aOptions, true );
    }

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

    if ( !wp_next_scheduled( 'fsp_cron_scheduler' ) ) {
      wp_schedule_event( time(), 'hourly', 'fsp_cron_scheduler');
    }
  }

  public static function fsp_activate() {
    $strSchedule = $this->isFlashmobDay() ? 'hourly' : 'twicedaily';
    if ( !wp_next_scheduled( 'fsp_cron' ) ) {
      wp_schedule_event( time(), $strSchedule, 'fsp_cron');
    }
    if ( !wp_next_scheduled( 'fsp_cron_scheduler' ) ) {
      wp_schedule_event( time(), 'hourly', 'fsp_cron_scheduler');
    }
  }
  public static function fsp_deactivate() {
    wp_clear_scheduled_hook('fsp_cron');
    wp_clear_scheduled_hook('fsp_cron_scheduler');
  }

  public function fsp_cron_scheduler() {
    $strSchedule = $this->isFlashmobDay() ? 'hourly' : 'twicedaily';
    if ( !wp_next_scheduled( 'fsp_cron' ) ) {
      wp_schedule_event( time(), $strSchedule, 'fsp_cron');
    } else {
      $strScheduleCurrent = wp_get_schedule( 'fsp_cron' );
      if ($strScheduleCurrent != $strSchedule) {
        if (false !== $strScheduleCurrent) {
          wp_clear_scheduled_hook('fsp_cron');
        }
        wp_schedule_event( time(), $strSchedule, 'fsp_cron');
      }
    }
  }

  public function fsp_cron( $bFixValues = false ) {
    // return; // turned off 2017/04/09 //
    // return; // turned on  2017/12/26 //

    $bEnableCron = (isset($this->aOptions['bEnableCron']) && $this->aOptions['bEnableCron'] === true) && $this->bIsSeason;

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
    if (class_exists("FLORP") && function_exists("florp_is_intf_blog") && florp_is_intf_blog()) {
      add_submenu_page(
        'florp-international',
        __( "International Flashmob Stats Parser", "fsp" ),
        __( "Stats Parser", "fsp" ),
        'manage_options',
        'fsp',
        array( $this, "options_page" )
      );
    } else {
      add_menu_page(
        __( "International Flashmob Stats Parser", "fsp" ),
        __( "Flashmob Stats Parser", "fsp" ),
        "manage_options",
        $this->strOptionsPageSlug,
        array( $this, "options_page" ),
        'dashicons-search',
        58
      );
    }
  }

  public function options_page() {
    echo "<div class=\"wrap\"><h1>" . __("International Flashmob Stats Parser Settings", "fsp" ) . "</h1>";

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
    $strOptionsMonthsFlashmob = "";
    $iSeasonStartMonth = $this->aOptions["iSeasonStartMonth"];
    $iSeasonEndMonth = $this->aOptions["iSeasonEndMonth"];
    $iFlashmobMonth = $this->aOptions["iFlashmobMonth"];
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
      if ($iFlashmobMonth == $i) {
        $strSelectedFlashmob = 'selected="selected"';
      } else {
        $strSelectedFlashmob = '';
      }
      $strMonthName = __( date('F', mktime(0, 0, 0, $i, 1, date('Y'))), 'fsp' );
      $strOptionsMonthsStart .= '<option value="'.$i.'" '.$strSelectedStart.'>'.$strMonthName.'</option>';
      $strOptionsMonthsEnd .= '<option value="'.$i.'" '.$strSelectedEnd.'>'.$strMonthName.'</option>';
      $strOptionsMonthsFlashmob .= '<option value="'.$i.'" '.$strSelectedFlashmob.'>'.$strMonthName.'</option>';
    }
    $strOptionsDaysStart = "";
    $strOptionsDaysEnd = "";
    $strOptionsDaysFlashmob = "";
    $iSeasonStartDay = $this->aOptions["iSeasonStartDay"];
    $iSeasonEndDay = $this->aOptions["iSeasonEndDay"];
    $iFlashmobDay = $this->aOptions["iFlashmobDay"];
    for ($i = 1; $i <= 31; $i++) {
      if ($iSeasonStartDay == $i) {
        $strSelectedStart = 'selected="selected"';
      } else {
        $strSelectedStart = '';
      }
      if ($iSeasonEndDay == $i) {
        $strSelectedEnd = 'selected="selected"';
      } else {
        $strSelectedEnd = '';
      }
      if ($iFlashmobDay == $i) {
        $strSelectedFlashmob = 'selected="selected"';
      } else {
        $strSelectedFlashmob = '';
      }
      $strOptionsDaysStart .= '<option value="'.$i.'" '.$strSelectedStart.'>'.$i.'</option>';
      $strOptionsDaysEnd .= '<option value="'.$i.'" '.$strSelectedEnd.'>'.$i.'</option>';
      $strOptionsDaysFlashmob .= '<option value="'.$i.'" '.$strSelectedFlashmob.'>'.$i.'</option>';
    }

    $strSeasonPlaceholder = ($this->bIsSeason) ? '%%inSeason%% %%labelCurrentSeason%%' : '%%outOfSeason%% %%labelNextSeason%%';
    echo str_replace(
      array(
        '%%enableCronChecked%%', "%%enableCronCheckedLabel%%", "%%seasonStartLabel%%", "%%seasonEndLabel%%", "%%saveButtonLabel%%",
        "%%optionsMonthsStart%%", "%%optionsMonthsEnd%%", "%%optionsDaysStart%%", "%%optionsDaysEnd%%", "%%seasonInfo%%", "%%optionsMonthsFlashmob%%", "%%optionsDaysFlashmob%%",
        "%%inSeason%%", "%%outOfSeason%%", "%%labelCurrentSeason%%", "%%labelNextSeason%%", "%%seasonFlashmobLabel%%", ),
      array(
        $strEnableCronChecked, __( "Enable Cron?", 'fsp' ), __( "Start of International Flashmob Season", 'fsp' ), __( "End of International Flashmob Season", 'fsp' ), __( "Save", 'fsp' ),
        $strOptionsMonthsStart, $strOptionsMonthsEnd, $strOptionsDaysStart, $strOptionsDaysEnd, __( "Info: Outside of Season, the cron is disabled. End of Season Month's year is taken as current season.", 'fsp' ), $strOptionsMonthsFlashmob, $strOptionsDaysFlashmob,
        __( "We are IN season, currently.", 'fsp' ), __( "We are NOT in season, currently.", 'fsp' ), __( "Current season: ", 'fsp' ). $this->iYear .".", __( "Next season: ", 'fsp' ). $this->iYear .".", __("Day of Flashmob", 'fsp') ),
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
              <th style="width: 47%; padding: 0 1%; text-align: right;"><label for="fsp_season_month_start">%%seasonStartLabel%%</label></th>
              <td><select id="fsp_season_month_start" name="fsp_season_month_start">%%optionsMonthsStart%%</select> / <select id="fsp_season_day_start" name="fsp_season_day_start">%%optionsDaysStart%%</select></td>
            </tr>
            <tr>
              <th style="width: 47%; padding: 0 1%; text-align: right;"><label for="fsp_season_month_end">%%seasonEndLabel%%</label></th>
              <td><select id="fsp_season_month_end" name="fsp_season_month_end">%%optionsMonthsEnd%%</select> / <select id="fsp_season_day_end" name="fsp_season_day_end">%%optionsDaysEnd%%</select></td>
            </tr>
            <tr>
              <th colspan="2">
                <span style="font-size: smaller;">%%seasonInfo%%</span>
              </th>
            </tr>
            <tr>
              <th style="width: 47%; padding: 0 1%; text-align: right;"><label for="fsp_flashmob_month">%%seasonFlashmobLabel%%</label></th>
              <td><select id="fsp_flashmob_month" name="fsp_flashmob_month">%%optionsMonthsFlashmob%%</select> / <select id="fsp_flashmob_day" name="fsp_flashmob_day">%%optionsDaysFlashmob%%</select></td>
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
        <h2 class="shortcode-examples-h2">' . __( "Shortcode Examples", "fsp" ) . '</h2>
        <div class="shortcode-examples">' . PHP_EOL
    );
    foreach( $this->aShortcodes as $strShortcode => $mixMethod ) {
      $bDoShortcode = true;
      $strInfo = "";
      $strShortcodeExample = '['.$strShortcode;
      if (is_array($mixMethod)) {
        if (isset($mixMethod['example-args'])) {
          $strShortcodeExample .= ' ' . $mixMethod['example-args'];
        }
        $bDoShortcode = !isset($mixMethod['front-end-only']) || !$mixMethod['front-end-only'];
        if (isset($mixMethod['info'])) {
          $strInfo = $mixMethod['info'];
        }
      }
      $strShortcodeExample .= ']';
      echo '<h3><code>' . $strShortcodeExample . '</code>' . $strInfo . '</h3>'.PHP_EOL;
      if ($bDoShortcode) {
        echo '<p>' . do_shortcode($strShortcodeExample) . '</p>'.PHP_EOL;
      } else {
        echo '<p><small>' . __( "This shortcode only works on the front-end.", "fsp" ) . '</small></p>'.PHP_EOL;
      }
    }
    echo  '
        </div><!-- .shortcode-examples -->
      </div><!-- .wrap -->'.PHP_EOL;
  }

  private function save_option_page_options( $aPostedOptions ) {
    // echo "<pre>" .var_export($aPostedOptions, true). "</pre>";
    $aOptionsToSave = array(
      'bEnableCron'       => (isset($aPostedOptions['fsp_enable_cron']) && $aPostedOptions['fsp_enable_cron'] === '1'),
      'iSeasonEndMonth'   => isset($aPostedOptions['fsp_season_month_end']) ? intval($aPostedOptions['fsp_season_month_end']) : $this->aOptionDefaults['iSeasonEndMonth'],
      'iSeasonStartMonth' => isset($aPostedOptions['fsp_season_month_start']) ? intval($aPostedOptions['fsp_season_month_start']) : $this->aOptionDefaults['iSeasonStartMonth'],
      'iSeasonEndDay'     => isset($aPostedOptions['fsp_season_day_end']) ? intval($aPostedOptions['fsp_season_day_end']) : $this->aOptionDefaults['iSeasonEndDay'],
      'iSeasonStartDay'   => isset($aPostedOptions['fsp_season_day_start']) ? intval($aPostedOptions['fsp_season_day_start']) : $this->aOptionDefaults['iSeasonStartDay'],
      'iFlashmobDay'     => isset($aPostedOptions['fsp_flashmob_day']) ? intval($aPostedOptions['fsp_flashmob_day']) : $this->aOptionDefaults['iFlashmobDay'],
      'iFlashmobMonth'   => isset($aPostedOptions['fsp_flashmob_month']) ? intval($aPostedOptions['fsp_flashmob_month']) : $this->aOptionDefaults['iFlashmobMonth'],
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
    } else {
      $bAllFieldsPresent = true;
      foreach ($this->aFields as $strField) {
        if (!isset($this->aStats[$iYear][$strField])) {
          $bAllFieldsPresent = false;
          break;
        }
      }
      if (!$bAllFieldsPresent) {
        // Year was not set and current season doesn't have any stats yet => Get last year with stats //
        krsort($this->aStats);
        foreach ($this->aStats as $iStatsYear => $aStatsOfYear) {
          if (!empty($aStatsOfYear)) {
            $bAllFieldsPresent = true;
            foreach ($this->aFields as $strField) {
              if (!isset($aStatsOfYear[$strField])) {
                $bAllFieldsPresent = false;
                break;
              }
            }
            if ($bAllFieldsPresent) {
              return $iStatsYear;
            }
          }
        }
      }
    }
    return $iYear;
  }

  private function get_current_year() {
    $iSeasonEndMonth = $this->aOptions["iSeasonEndMonth"];
    $iSeasonEndDay = $this->aOptions["iSeasonEndDay"];
    $iSeasonEnd = $iSeasonEndMonth * 100 + $iSeasonEndDay;

    $iMonthDayNow = intval(date( 'n' )) * 100 + intval(date( 'j' ));
    $iYearNow = intval( date( "Y" ) );

    if ($iMonthDayNow <= $iSeasonEnd) {
      // We are before the end of season //
      return $iYearNow;
    } else {
      // We are after the end of season //
      return $iYearNow + 1;
    }
  }

  private function isSeason() {
    $iSeasonStartMonth = $this->aOptions["iSeasonStartMonth"];
    $iSeasonStartDay = $this->aOptions["iSeasonStartDay"];
    $iSeasonStart = $iSeasonStartMonth * 100 + $iSeasonStartDay;

    $iSeasonEndMonth = $this->aOptions["iSeasonEndMonth"];
    $iSeasonEndDay = $this->aOptions["iSeasonEndDay"];
    $iSeasonEnd = $iSeasonEndMonth * 100 + $iSeasonEndDay;

    $iMonthDayNow = intval(date( 'n' )) * 100 + intval(date( 'j' ));

    if ($iSeasonStart == $iSeasonEnd ) {
      return true;
    } elseif ($iSeasonStart < $iSeasonEnd && ($iSeasonStart > $iMonthDayNow || $iSeasonEnd < $iMonthDayNow)) {
      // We are NOT BETWEEN start and end //
      return false;
    } elseif ($iSeasonStart > $iSeasonEnd && ($iSeasonEnd < $iMonthDayNow && $iSeasonStart > $iMonthDayNow)) {
      // We are BETWEEN end and start //
      return false;
    }
    // var_dump($iSeasonStart, $iSeasonEndMonth, $iMonthDayNow);
    return true;
  }

  private function isFlashmobDay() {
    $iFlashmobMonth = $this->aOptions['iFlashmobMonth'];
    $iFlashmobDay = $this->aOptions['iFlashmobDay'];

    $iMonthDayFlashmob = $iFlashmobMonth * 100 + $iFlashmobDay;
    $iMonthDayNow = intval(date( 'n' )) * 100 + intval(date( 'j' ));

    return ($iMonthDayFlashmob == $iMonthDayNow);
  }
}

$FSP = new FSP();
register_activation_hook(__FILE__, array('FSP', 'fsp_activate'));
register_deactivation_hook(__FILE__, array('FSP', 'fsp_deactivate'));
