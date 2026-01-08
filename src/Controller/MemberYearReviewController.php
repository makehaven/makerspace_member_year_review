<?php

namespace Drupal\makerspace_member_year_review\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\makerspace_member_year_review\Service\MemberStatsService;
use Drupal\Core\Access\AccessResult;
use Drupal\makerspace_dashboard\Service\MembershipMetricsService;
use Drupal\makerspace_dashboard\Service\EventsMembershipDataService;
use Drupal\makerspace_dashboard\Service\UtilizationDataService;
use Drupal\makerspace_dashboard\Service\EngagementDataService;
use Drupal\makerspace_dashboard\Service\AppointmentInsightsService;
use Drupal\lending_library\Service\StatsCollectorInterface;
use Drupal\makerspace_dashboard\Service\DemographicsDataService;
use Drupal\Component\Utility\Html;

use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for Member Year in Review.
 */
class MemberYearReviewController extends ControllerBase {

  /**
   * Redirects the current user to their Year in Review page.
   */
  public function redirectCurrentUser() {
    if ($this->currentUser()->isAuthenticated()) {
      return $this->redirect('makerspace_member_year_review.page', ['user' => $this->currentUser()->id()]);
    }
    else {
      // Redirect to login with destination back to this route
      $options = ['query' => ['destination' => Url::fromRoute('makerspace_member_year_review.redirect')->toString()]];
      return $this->redirect('user.login', [], $options);
    }
  }

  /**
   * The stats service.
   *
   * @var \Drupal\makerspace_member_year_review\Service\MemberStatsService
   */
  protected $statsService;

  protected $membershipMetrics;
  protected $eventsData;
  protected $utilizationData;
  protected $engagementData;
  protected $appointmentInsights;
  protected $lendingStats;
  protected $demographicsData;

  /**
   * Constructs the controller.
   */
  public function __construct(
    MemberStatsService $stats_service,
    MembershipMetricsService $membership_metrics,
    EventsMembershipDataService $events_data,
    UtilizationDataService $utilization_data,
    EngagementDataService $engagement_data,
    AppointmentInsightsService $appointment_insights,
    StatsCollectorInterface $lending_stats,
    DemographicsDataService $demographics_data
  ) {
    $this->statsService = $stats_service;
    $this->membershipMetrics = $membership_metrics;
    $this->eventsData = $events_data;
    $this->utilizationData = $utilization_data;
    $this->engagementData = $engagement_data;
    $this->appointmentInsights = $appointment_insights;
    $this->lendingStats = $lending_stats;
    $this->demographicsData = $demographics_data;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('makerspace_member_year_review.stats'),
      $container->get('makerspace_dashboard.membership_metrics'),
      $container->get('makerspace_dashboard.events_membership_data'),
      $container->get('makerspace_dashboard.utilization_data'),
      $container->get('makerspace_dashboard.engagement_data'),
      $container->get('makerspace_dashboard.appointment_insights'),
      $container->get('lending_library.stats_collector'),
      $container->get('makerspace_dashboard.demographics_data')
    );
  }

  /**
   * Displays the Year in Review page.
   */
  public function page(UserInterface $user) {
    // Hardcoded to 2025 per requirement, or previous year logic
    $year = 2025; 
    $prev_year = $year - 1;
    
    // Fetch Data Current Year
    $visit_days = $this->statsService->getVisitDays($user->id(), $year);
    $attendance = $this->statsService->getEventAttendance($user->id(), $year);
    $badges = $this->statsService->getBadgesEarned($user->id(), $year);
    $loans = $this->statsService->getLendingUsage($user->id(), $year);

    // Fetch Data Previous Year (for comparison)
    $visit_days_prev = $this->statsService->getVisitDays($user->id(), $prev_year);
    $attendance_prev = $this->statsService->getEventAttendance($user->id(), $prev_year);
    $badges_prev = $this->statsService->getBadgesEarned($user->id(), $prev_year);
    $loans_prev = $this->statsService->getLendingUsage($user->id(), $prev_year);

    // Member Info
    $profile_info = $this->statsService->getMemberProfileInfo($user->id());

    // Calculate Deltas
    $deltas = [
      'visits' => NULL, // Data incomplete for 2024
      'events' => $this->calculateDelta($attendance['count'], $attendance_prev['count']),
      'badges' => $this->calculateDelta(count($badges), count($badges_prev)),
      'loans' => $this->calculateDelta($loans, $loans_prev),
    ];

    // --- Community Stats & Ranking ---
    $start = new \DateTimeImmutable("$year-01-01");
    $end = new \DateTimeImmutable("$year-12-31 23:59:59");

    // 1. Total Active Members (approximate for year end)
    $active_members_count = $this->membershipMetrics->getMonthlyActiveMemberCounts(1)[0]['count'] ?? $profile_info['total_members'];

    // Appointments
    $appointments = $this->statsService->getAppointments($user->id(), $year);

    // 2. Calculate Ranks
    $ranks = [
      'visits' => $this->estimateRank($visit_days, 'visits'),
      'badges' => $this->estimateRank(count($badges), 'badges'),
      'events' => $this->estimateRank($attendance['count'], 'events'),
      'loans' => $this->estimateRank($loans, 'loans'),
      'appointments' => $this->estimateRank($appointments, 'events'), // Reuse events thresholds for now
      'visits_num' => $this->statsService->getVisitDaysRank($user->id(), $year, $visit_days),
      'badges_num' => $this->statsService->getBadgesEarnedRank($user->id(), $year, count($badges)),
      'appointments_num' => $this->statsService->getAppointmentRank($user->id(), $year, $appointments),
    ];

    // 3. Community Highlights (Simplified for top teaser)
    $community_stats = [
      'total_members' => number_format($active_members_count),
      'new_members' => $this->getCommunityNewMembers($start, $end),
      'workshops_held' => $this->getCommunityWorkshopsHeld($start, $end),
      'badges_earned' => $this->getCommunityBadgesEarned($start, $end),
    ];

    // 4. Community Full Report (Cached)
    $community_full_report = $this->getCommunityYearStats($year);

    // 5. Fun Awards
    $fun_award = $this->statsService->getMakerPersona($user->id(), $year, count($badges), $attendance['count'], $loans);

    $first_name = $user->getDisplayName();
    if ($user->hasField('field_first_name') && !$user->get('field_first_name')->isEmpty()) {
      $first_name = $user->get('field_first_name')->value;
    }

    return [
      '#theme' => 'member_year_review',
      '#user_name' => $user->getDisplayName(),
      '#first_name' => $first_name,
      '#user_id' => $user->id(),
      '#year' => $year,
      '#profile' => $profile_info,
      '#deltas' => $deltas,
      '#ranks' => $ranks,
      '#active_members_total' => $active_members_count,
      '#community_stats' => $community_stats,
      '#community_full_report' => $community_full_report,
      '#fun_award' => $fun_award,
      '#stats' => [
        'visits' => $visit_days,
        'visits_prev' => $visit_days_prev,
        'events_count' => $attendance['count'],
        'events_list' => $attendance['events'],
        'badges_count' => count($badges),
        'badges_list' => $badges,
        'loans_count' => $loans,
        'appointments' => $appointments,
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['user:' . $user->id(), 'community_year_stats:' . $year],
        'max-age' => 3600, 
      ],
      '#attached' => [
        'library' => [
          'makerspace_dashboard/annual_report',
          'makerspace_dashboard/location_map',
        ],
        'drupalSettings' => [
          'makerspace_dashboard' => [
            'locations_url' => '/makerspace-dashboard/api/locations',
          ],
        ],
      ],
    ];
  }

  /**
   * Fetches and caches community stats for the year.
   */
  protected function getCommunityYearStats(int $year): array {
    $cid = 'makerspace_member_year_review:community_stats:' . $year;
    if ($cache = \Drupal::cache()->get($cid)) {
      return $cache->data;
    }

    $start = new \DateTimeImmutable("$year-01-01");
    $end = new \DateTimeImmutable("$year-12-31 23:59:59");

    // --- STATS ---
    $stats = [];
    
    // Total Joins
    $stats['total_joins'] = $this->getCommunityNewMembers($start, $end);

    // Workshops
    $stats['workshops_held'] = $this->getCommunityWorkshopsHeld($start, $end);

    // Workshop Registrations
    $workshopEventTypeId = 6;
    $query = \Drupal::database()->select('civicrm_participant', 'cp');
    $query->join('civicrm_event', 'ce', 'cp.event_id = ce.id');
    $query->condition('ce.event_type_id', $workshopEventTypeId);
    $query->condition('ce.start_date', $start->format('Y-m-d H:i:s'), '>=');
    $query->condition('ce.start_date', $end->format('Y-m-d H:i:s'), '<');
    $stats['workshop_registrations'] = number_format((int) $query->countQuery()->execute()->fetchField());

    // Volunteer Appointments
    $appointmentData = $this->appointmentInsights->getFeedbackOutcomeSeries($start, $end);
    $stats['appointments'] = number_format($appointmentData['totals']['appointments'] ?? 0);

    // Tool Loans (Simplified approximation for cache speed if service doesn't support range well, 
    // but here we use the collector data logic from AnnualReportController)
    $lendingStats = $this->lendingStats->collect();
    $lendingHistory = $lendingStats['chart_data']['full_history'] ?? [];
    $loanCount = 0;
    foreach ($lendingHistory as $monthData) {
      $monthDate = \DateTimeImmutable::createFromFormat('M Y', $monthData['label']);
      if ($monthDate && $monthDate >= $start && $monthDate <= $end) {
        $loanCount += (int)($monthData['loans'] ?? 0);
      }
    }
    $stats['tool_loans'] = number_format($loanCount);

    // Badges Earned
    $stats['badges_earned'] = $this->getCommunityBadgesEarned($start, $end);

    // Total Visits
    $dailyEntries = $this->utilizationData->getDailyUniqueEntries($start->getTimestamp(), $end->getTimestamp(), FALSE);
    $stats['total_visits'] = number_format(array_sum($dailyEntries));


    // --- CHARTS ---
    $charts = [];

    // 1. Member Location Heatmap
    $mapId = Html::getUniqueId('member-location-map-community');
    $charts['heatmap'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['makerspace-dashboard-location-map-wrapper']],
        'map' => [
          '#type' => 'container',
          '#attributes' => [
            'id' => $mapId,
            'class' => ['makerspace-dashboard-location-map'],
            'data-locations-url' => '/makerspace-dashboard/api/locations',
            'data-initial-view' => 'heatmap',
            'data-fit-bounds' => 'false',
            'data-zoom' => '10',
            'style' => 'height: 600px;',
          ],
        ],
        'controls' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['makerspace-dashboard-map-controls', 'text-center', 'mt-2']],
          'heatmap_btn' => [
            '#type' => 'html_tag',
            '#tag' => 'button',
            '#value' => $this->t('Heatmap'),
            '#attributes' => ['class' => ['button', 'button--small', 'btn', 'btn-sm', 'btn-primary', 'text-white', 'me-2'], 'data-map-view' => 'heatmap'],
          ],
          'markers_btn' => [
            '#type' => 'html_tag',
            '#tag' => 'button',
            '#value' => $this->t('Clusters'),
            '#attributes' => ['class' => ['button', 'button--small', 'btn', 'btn-sm', 'btn-primary', 'text-white'], 'data-map-view' => 'markers'],
          ],
        ],
    ];

    // 2. Members by Badges Earned (In this year)
    $badgeCountData = $this->getCommunityBadgeDistribution($start, $end);
    if ($badgeCountData) {
        $badgeCountLabels = array_column($badgeCountData, 'label');
        $badgeCountValues = array_column($badgeCountData, 'count');
        
        $charts['badge_dist'] = [
            '#type' => 'chart',
            '#chart_type' => 'column',
            '#height' => 600,
            '#height_units' => 'px',
            'xaxis' => ['#type' => 'chart_xaxis', '#labels' => $badgeCountLabels],
            'yaxis' => ['#type' => 'chart_yaxis', '#title' => $this->t('Members')],
            'members' => ['#type' => 'chart_data', '#title' => $this->t('Members'), '#data' => $badgeCountValues, '#color' => '#16a34a'],
        ];
    }

    // 3. Monthly Badges Issued
    $badgeIssuance = $this->engagementData->getMonthlyBadgeIssuance($start, $end);
    if (!empty($badgeIssuance['labels'])) {
        $charts['badge_issuance'] = [
            '#type' => 'chart',
            '#chart_type' => 'column',
            '#height' => 600,
            '#height_units' => 'px',
            'xaxis' => ['#type' => 'chart_xaxis', '#labels' => $badgeIssuance['labels']],
            'yaxis' => ['#type' => 'chart_yaxis', '#title' => $this->t('Badges')],
            'badges' => ['#type' => 'chart_data', '#title' => $this->t('Badges Issued'), '#data' => $badgeIssuance['counts'], '#color' => '#8b5cf6'],
        ];
    }

    // 4. Total Monthly Visits
    // Aggregate daily entries to monthly
    $monthlyEntries = [];
    foreach ($dailyEntries as $date => $count) {
      // Filter to only include the specific year (prevent timezone bleed into next year)
      if (strpos($date, (string) $year) !== 0) {
        continue;
      }
      $month = substr($date, 0, 7); // YYYY-MM
      if (!isset($monthlyEntries[$month])) $monthlyEntries[$month] = 0;
      $monthlyEntries[$month] += $count;
    }
    
    // Sort just in case
    ksort($monthlyEntries);
    
    $visitLabels = [];
    $visitValues = [];
    foreach ($monthlyEntries as $m => $c) {
        $visitLabels[] = (new \DateTimeImmutable($m . '-01'))->format('M');
        $visitValues[] = $c;
    }

    if ($visitValues) {
        $charts['monthly_visits'] = [
            '#type' => 'chart',
            '#chart_type' => 'column',
            '#height' => 600,
            '#height_units' => 'px',
            'xaxis' => ['#type' => 'chart_xaxis', '#labels' => $visitLabels],
            'yaxis' => ['#type' => 'chart_yaxis', '#title' => $this->t('Visits')],
            'visits' => ['#type' => 'chart_data', '#title' => $this->t('Total Visits'), '#data' => $visitValues, '#color' => '#10b981'],
        ];
    }

    // 5. First Entry Time by Weekday (Stacked)
    $entryBuckets = $this->utilizationData->getFirstEntryBucketsByWeekday($start->getTimestamp(), $end->getTimestamp());
    if ($entryBuckets) {
        $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $series = [
            'early_morning' => ['label' => 'Early Bird (5am-9am)', 'data' => [], 'color' => '#ef4444'],
            'morning' => ['label' => 'Morning Maker (9am-12pm)', 'data' => [], 'color' => '#f97316'],
            'noon' => ['label' => 'Lunch Crew (12pm-2pm)', 'data' => [], 'color' => '#eab308'],
            'afternoon' => ['label' => 'Afternoon Artisan (2pm-6pm)', 'data' => [], 'color' => '#3b82f6'],
            'evening' => ['label' => 'Evening Regular (6pm-10pm)', 'data' => [], 'color' => '#4338ca'],
            'night' => ['label' => 'Night Owl (10pm-5am)', 'data' => [], 'color' => '#000000'],
        ];

        // Remap 0-6 (Sun-Sat) to 0-6 (Mon-Sun)
        // 0=Sun -> 6
        // 1=Mon -> 0
        $map = [1 => 0, 2 => 1, 3 => 2, 4 => 3, 5 => 4, 6 => 5, 0 => 6];
        $sortedBuckets = array_fill(0, 7, []);

        foreach ($entryBuckets as $dayIdx => $times) {
            $newIdx = $map[$dayIdx];
            $sortedBuckets[$newIdx] = $times;
        }
        
        // Build series data
        foreach ($sortedBuckets as $times) {
            foreach ($series as $key => &$info) {
                $info['data'][] = (int) ($times[$key] ?? 0);
            }
        }

        $charts['entry_time_stacked'] = [
            '#type' => 'chart',
            '#chart_type' => 'column',
            '#height' => 600,
            '#height_units' => 'px',
            '#stacking' => TRUE,
            'xaxis' => ['#type' => 'chart_xaxis', '#labels' => $days],
            'yaxis' => ['#type' => 'chart_yaxis', '#title' => $this->t('Entries')],
            'legend' => ['#type' => 'chart_legend', '#position' => 'right', '#font_size' => 14],
        ];
        
        $series_titles = [
            'early_morning' => 'Early Bird (5am-9am)',
            'morning' => 'Morning Maker (9am-12pm)',
            'noon' => 'Lunch Crew (12pm-2pm)',
            'afternoon' => 'Afternoon Artisan (2pm-6pm)',
            'evening' => 'Evening Regular (6pm-10pm)',
            'night' => 'Night Owl (10pm-5am)',
        ];

        foreach ($series as $key => $info) {
            $charts['entry_time_stacked'][$key] = [
                '#type' => 'chart_data',
                '#title' => $series_titles[$key],
                '#data' => $info['data'],
                '#color' => $info['color'],
            ];
        }
    }

    $data = [
      'stats' => $stats,
      'charts' => $charts,
      'leaderboards' => [
        'badges_year' => $this->statsService->getBadgeLeaderboard($year, 10),
        'visits_year' => $this->statsService->getVisitLeaderboard($year, 10),
        'badges_overall' => $this->statsService->getOverallBadgeLeaderboard(10),
      ],
    ];

    // Cache until next year? Or for 24h.
    \Drupal::cache()->set($cid, $data, time() + 86400, ['node_list:badge_request', 'civicrm_event_list', 'profile_list']);

    return $data;
  }

  private function getCommunityNewMembers($start, $end) {
    // Simplified query for speed, similar to AnnualReport
    $query = \Drupal::database()->select('profile', 'p');
    $query->condition('p.type', 'main');
    $query->condition('p.created', $start->getTimestamp(), '>=');
    $query->condition('p.created', $end->getTimestamp(), '<');
    return number_format((int) $query->countQuery()->execute()->fetchField());
  }

  private function getCommunityWorkshopsHeld($start, $end) {
    $workshopEventTypeId = 6; // Default
    $query = \Drupal::database()->select('civicrm_event', 'e');
    $query->condition('e.event_type_id', $workshopEventTypeId);
    $query->condition('e.start_date', $start->format('Y-m-d H:i:s'), '>=');
    $query->condition('e.start_date', $end->format('Y-m-d H:i:s'), '<');
    return number_format((int) $query->countQuery()->execute()->fetchField());
  }

  private function getCommunityBadgesEarned($start, $end) {
    $query = \Drupal::database()->select('node__field_member_to_badge', 'mtb');
    $query->innerJoin('node_field_data', 'n', 'n.nid = mtb.entity_id');
    $query->innerJoin('node__field_badge_status', 'status', 'status.entity_id = n.nid');
    $query->condition('n.type', 'badge_request');
    $query->condition('status.field_badge_status_value', 'active');
    $query->condition('n.created', [$start->getTimestamp(), $end->getTimestamp()], 'BETWEEN');
    return number_format((int) $query->countQuery()->execute()->fetchField());
  }

  /**
   * Helper to estimate rank text.
   * Real percentile calculation would require a distribution query.
   * For now, we use thresholds based on typical maker activity.
   */
  private function estimateRank($value, $type) {
    if ($value == 0) return NULL;

    // Thresholds (Example: 50 visits is top 10% approx)
    $thresholds = [
      'visits' => [100 => 'Top 1%', 50 => 'Top 5%', 25 => 'Top 10%', 12 => 'Top 25%', 1 => 'Active Member'],
      'badges' => [10 => 'Top 1%', 5 => 'Top 5%', 3 => 'Top 10%', 1 => 'Badge Earner'],
      'events' => [20 => 'Top 1%', 10 => 'Top 5%', 5 => 'Top 10%', 1 => 'Learner'],
      'loans' => [20 => 'Top 1%', 10 => 'Top 5%', 5 => 'Top 10%', 1 => 'Borrower'],
    ];

    $label = 'Member';
    $is_top = FALSE;

    foreach ($thresholds[$type] as $limit => $text) {
      if ($value >= $limit) {
        $label = $text;
        if (strpos($text, 'Top') === 0) {
          $is_top = TRUE;
        }
        break;
      }
    }
    
    return [
      'label' => $label,
      'is_top' => $is_top,
    ];
  }

  /**
   * Helper to calculate percentage change.
   */
  private function calculateDelta($current, $previous) {
    if ($previous == 0) {
      return $current > 0 ? '+100%' : '0%';
    }
    $diff = $current - $previous;
    $percent = round(($diff / $previous) * 100);
    return ($percent > 0 ? '+' : '') . $percent . '%';
  }

  /**
   * Gets badge distribution for the specific year.
   */
  private function getCommunityBadgeDistribution(\DateTimeImmutable $start, \DateTimeImmutable $end): array {
    $query = \Drupal::database()->select('node_field_data', 'n');
    $query->addExpression('COUNT(DISTINCT n.nid)', 'badge_count');
    $query->addField('n', 'uid');
    $query->innerJoin('node__field_badge_status', 'status', 'status.entity_id = n.nid');
    
    // Only badges earned in this period
    $query->condition('n.type', 'badge_request');
    $query->condition('n.status', 1);
    $query->condition('status.field_badge_status_value', 'active');
    $query->condition('n.created', [$start->getTimestamp(), $end->getTimestamp()], 'BETWEEN');
    
    // Group by user to see how many badges each user earned
    $query->groupBy('n.uid');
    
    $results = $query->execute();
    
    // Initialize buckets
    $buckets = [
        '1' => ['label' => '1 badge', 'count' => 0],
        '2' => ['label' => '2 badges', 'count' => 0],
        '3' => ['label' => '3 badges', 'count' => 0],
        '4' => ['label' => '4 badges', 'count' => 0],
        '5-9' => ['label' => '5-9 badges', 'count' => 0],
        '10+' => ['label' => '10+ badges', 'count' => 0],
    ];

    foreach ($results as $row) {
        $c = (int) $row->badge_count;
        if ($c == 1) $buckets['1']['count']++;
        elseif ($c == 2) $buckets['2']['count']++;
        elseif ($c == 3) $buckets['3']['count']++;
        elseif ($c == 4) $buckets['4']['count']++;
        elseif ($c >= 5 && $c <= 9) $buckets['5-9']['count']++;
        elseif ($c >= 10) $buckets['10+']['count']++;
    }
    
    return array_values($buckets);
  }

  /**
   * Access check for the page.
   */
  public function access(AccountInterface $account, UserInterface $user) {
    return AccessResult::allowedIf(
      ($account->id() == $user->id()) || $account->hasPermission('administer users')
    );
  }

}
