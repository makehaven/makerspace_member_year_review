<?php

namespace Drupal\makerspace_member_year_review\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\makerspace_dashboard\Service\UtilizationDataService;

/**
 * Service to retrieve member statistics for year in review.
 */
class MemberStatsService {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Goal definitions map.
   */
  protected $goalsMap = [
    'artist' => 'Create art',
    'skill_builder' => 'Build skills',
    'hobbyist' => 'Practice a hobby',
    'inventor' => 'Develop a prototype/product',
    'entrepreneur' => 'Business entrepreneurship',
    'seller' => 'Produce products/art to sell',
    'networker' => 'Connect with others',
    'other' => 'Other',
  ];

  /**
   * The utilization data service.
   *
   * @var \Drupal\makerspace_dashboard\Service\UtilizationDataService
   */
  protected $utilizationData;

  /**
   * Constructs a MemberStatsService object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\makerspace_dashboard\Service\UtilizationDataService $utilization_data
   *   The utilization data service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, Connection $database, UtilizationDataService $utilization_data) {
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
    $this->utilizationData = $utilization_data;
  }

  /**
   * Gets the user's primary Maker Persona.
   */
  public function getMakerPersona(int $uid, int $year, int $badges_count, int $events_count, int $loans_count): ?array {
    try {
      $query = $this->database->select('access_control_log_field_data', 'a');
      $query->join('access_control_log__field_access_request_user', 'u', 'a.id = u.entity_id');
      $query->condition('u.field_access_request_user_target_id', $uid);
      $query->where('YEAR(FROM_UNIXTIME(a.created)) = :year', [':year' => $year]);
      
      $query->addExpression("DATE(FROM_UNIXTIME(a.created))", 'visit_date');
      $query->addExpression("MIN(a.created)", 'first_entry');
      $query->groupBy('visit_date');
      
      $results = $query->execute();
      
      $time_buckets = [
        'Early Bird' => 0,
        'Morning Maker' => 0,
        'Lunch Crew' => 0,
        'Afternoon Artisan' => 0,
        'Evening Regular' => 0,
        'Night Owl' => 0,
      ];

      $weekend_visits = 0;
      $total_visits = 0;
      $tz = new \DateTimeZone('America/New_York');

      foreach ($results as $row) {
        $total_visits++;
        $ts = (int) $row->first_entry;
        $date = new \DateTime('@' . $ts);
        $date->setTimezone($tz);
        
        $h = (int) $date->format('G');
        $w = (int) $date->format('w'); // 0 (Sun) to 6 (Sat)

        if ($w == 0 || $w == 6) $weekend_visits++;

        if ($h >= 5 && $h < 9) $time_buckets['Early Bird']++;
        elseif ($h >= 9 && $h < 12) $time_buckets['Morning Maker']++;
        elseif ($h >= 12 && $h < 14) $time_buckets['Lunch Crew']++;
        elseif ($h >= 14 && $h < 18) $time_buckets['Afternoon Artisan']++;
        elseif ($h >= 18 && $h < 22) $time_buckets['Evening Regular']++;
        else $time_buckets['Night Owl']++;
      }

      if ($total_visits === 0 && $badges_count === 0 && $events_count === 0 && $loans_count === 0) {
        return NULL;
      }

      // --- Persona Logic (Priority order) ---
      
      // 1. Master Badge Earner
      if ($badges_count >= 8) {
        return [
          'label' => 'Master Badge Earner',
          'description' => 'You are in the elite tier of badge earners! Your commitment to learning new tools is inspiring.',
          'icon' => 'fa-medal',
          'range' => 'Achievement Unlocked',
        ];
      }

      // 2. Workshop Enthusiast
      if ($events_count >= 10) {
        return [
          'label' => 'Workshop Enthusiast',
          'description' => 'You are a staple of our educational programs. Thank you for being such an active learner!',
          'icon' => 'fa-graduation-cap',
          'range' => 'Community Regular',
        ];
      }

      // 3. Weekend Warrior
      if ($total_visits >= 5 && ($weekend_visits / $total_visits) > 0.6) {
        return [
          'label' => 'Weekend Warrior',
          'description' => 'While others are resting, you are making! Saturday and Sunday are your time to shine.',
          'icon' => 'fa-calendar-check',
          'range' => 'Sat & Sun Regular',
        ];
      }

      // 4. Tool Specialist
      if ($loans_count >= 15) {
        return [
          'label' => 'Tool Specialist',
          'description' => 'You make great use of the lending library, bringing MakeHaven tools into your home projects.',
          'icon' => 'fa-toolbox',
          'range' => 'Expert Borrower',
        ];
      }

      // 5. Time-of-day Fallbacks
      arsort($time_buckets);
      $top_time = array_key_first($time_buckets);
      
      $descriptions = [
        'Early Bird' => 'You start your making before the day begins!',
        'Morning Maker' => 'You make the most of the morning light.',
        'Lunch Crew' => 'Taking a break to make!',
        'Afternoon Artisan' => 'Crafting through the afternoon.',
        'Evening Regular' => 'You\'re a staple of the evening community.',
        'Night Owl' => 'Burning the midnight oil!',
      ];
      $icons = [
        'Early Bird' => 'fa-coffee', 'Morning Maker' => 'fa-sun', 'Lunch Crew' => 'fa-utensils',
        'Afternoon Artisan' => 'fa-tools', 'Evening Regular' => 'fa-moon', 'Night Owl' => 'fa-bed',
      ];
      $ranges = [
        'Early Bird' => '5am - 9am', 'Morning Maker' => '9am - 12pm', 'Lunch Crew' => '12pm - 2pm',
        'Afternoon Artisan' => '2pm - 6pm', 'Evening Regular' => '6pm - 10pm', 'Night Owl' => '10pm - 5am',
      ];

      return [
        'label' => $top_time,
        'description' => $descriptions[$top_time],
        'icon' => $icons[$top_time],
        'range' => $ranges[$top_time],
      ];

    } catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Gets the number of unique days a user visited in a given year.
   */
  public function getVisitDays(int $uid, int $year): int {
    try {
      $query = $this->database->select('access_control_log_field_data', 'a');
      $query->join('access_control_log__field_access_request_user', 'u', 'a.id = u.entity_id');
      $query->condition('u.field_access_request_user_target_id', $uid);
      
      try {
          $query->join('access_control_log__field_access_request_result', 'r', 'a.id = r.entity_id');
          $query->condition('r.field_access_request_result_value', 1);
      } catch (\Exception $e) {}
      
      $query->addExpression("FROM_UNIXTIME(a.created, '%Y-%m-%d')", 'visit_date');
      $query->where('YEAR(FROM_UNIXTIME(a.created)) = :year', [':year' => $year]);
      $query->groupBy('visit_date');
      
      return (int) $query->countQuery()->execute()->fetchField();
    }
    catch (\Exception $e) {
      return 0;
    }
  }

  /**
   * Gets the number of events attended by a user in a given year.
   */
  public function getEventAttendance(int $uid, int $year): array {
    try {
      $query = $this->database->select('civicrm_uf_match', 'uf');
      $query->addField('uf', 'contact_id');
      $query->condition('uf.uf_id', $uid);
      $contact_id = $query->execute()->fetchField();

      if (!$contact_id) {
        return ['count' => 0, 'events' => []];
      }

      $query = $this->database->select('civicrm_participant', 'p');
      $query->join('civicrm_event', 'e', 'p.event_id = e.id');
      $query->addField('e', 'title');
      $query->addField('e', 'start_date');
      $query->condition('p.contact_id', $contact_id);
      $query->condition('p.status_id', [1, 2], 'IN'); 
      $query->condition('p.is_test', 0);
      $query->where('YEAR(e.start_date) = :year', [':year' => $year]);
      $query->orderBy('e.start_date', 'DESC');
      
      $results = $query->execute()->fetchAll();
      return [
        'count' => count($results),
        'events' => array_map(function($row) { return $row->title; }, $results),
      ];
    }
    catch (\Exception $e) {
      return ['count' => 0, 'events' => []];
    }
  }

  /**
   * Gets badges earned by a user in a given year.
   */
  public function getBadgesEarned(int $uid, int $year): array {
    try {
      $query = $this->entityTypeManager->getStorage('node')->getQuery();
      $query->condition('type', 'badge_request');
      $query->condition('uid', $uid);
      $query->accessCheck(FALSE);
      $query->condition('created', [mktime(0,0,0,1,1,$year), mktime(23,59,59,12,31,$year)], 'BETWEEN');
      
      $nids = $query->execute();
      if (empty($nids)) {
        return [];
      }
      
      $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
      $badges = [];
      foreach ($nodes as $node) {
        if ($node->hasField('field_badge_requested') && !$node->get('field_badge_requested')->isEmpty()) {
          $term = $node->get('field_badge_requested')->entity;
          if ($term) {
            $badges[] = $term->label();
          }
        } else {
          $badges[] = $node->getTitle();
        }
      }
      // Ensure unique badges if requested multiple times? Probably good.
      return array_unique($badges);
    }
    catch (\Exception $e) {
      return [];
    }
  }

  /**
   * Gets lending library usage stats.
   */
  public function getLendingUsage(int $uid, int $year): int {
    try {
      $query = $this->entityTypeManager->getStorage('library_transaction')->getQuery();
      $query->condition('field_library_borrower', $uid);
      $query->condition('field_library_borrow_date', $year . '-01-01', '>=');
      $query->condition('field_library_borrow_date', $year . '-12-31', '<=');
      $query->accessCheck(FALSE);
      
      return (int) $query->count()->execute();
    }
    catch (\Exception $e) {
      return 0;
    }
  }

  /**
   * Gets the rank of a user based on visit days among active members.
   */
  public function getVisitDaysRank(int $uid, int $year, int $user_days): int {
    if ($user_days === 0) {
      return 0;
    }

    try {
      $query = $this->database->select('access_control_log_field_data', 'acl');
      $query->addExpression('COUNT(DISTINCT DATE(FROM_UNIXTIME(acl.created)))', 'days');
      $query->innerJoin('access_control_log__field_access_request_user', 'user_ref', 'user_ref.entity_id = acl.id');
      $query->innerJoin('user__roles', 'ur', 'ur.entity_id = user_ref.field_access_request_user_target_id');
      $query->leftJoin('user__field_chargebee_payment_pause', 'pause', 'pause.entity_id = user_ref.field_access_request_user_target_id');
      
      $query->condition('acl.type', 'access_control_request');
      
      $or = $query->orConditionGroup()
        ->condition('ur.roles_target_id', ['member', 'current_member'], 'IN')
        ->condition('pause.field_chargebee_payment_pause_value', 1);
      $query->condition($or);
      
      $query->where('YEAR(FROM_UNIXTIME(acl.created)) = :year', [':year' => $year]);
      
      $query->groupBy('user_ref.field_access_request_user_target_id');
      $query->having('days > :user_days', [':user_days' => $user_days]);
      
      return (int) $query->countQuery()->execute()->fetchField() + 1;
    }
    catch (\Exception $e) {
      return 0;
    }
  }

  /**
   * Gets the rank of a user based on badges earned among active members.
   */
  public function getBadgesEarnedRank(int $uid, int $year, int $user_badges): int {
    if ($user_badges === 0) {
      return 0;
    }

    try {
      $start = mktime(0, 0, 0, 1, 1, $year);
      $end = mktime(23, 59, 59, 12, 31, $year);

      $query = $this->database->select('node_field_data', 'n');
      $query->addExpression('COUNT(DISTINCT n.nid)', 'badge_count');
      $query->innerJoin('node__field_badge_status', 'status', 'status.entity_id = n.nid');
      $query->innerJoin('user__roles', 'ur', 'ur.entity_id = n.uid');
      $query->leftJoin('user__field_chargebee_payment_pause', 'pause', 'pause.entity_id = n.uid');
      
      $query->condition('n.type', 'badge_request');
      $query->condition('n.status', 1);
      $query->condition('status.field_badge_status_value', 'active');
      
      $or = $query->orConditionGroup()
        ->condition('ur.roles_target_id', ['member', 'current_member'], 'IN')
        ->condition('pause.field_chargebee_payment_pause_value', 1);
      $query->condition($or);
      
      $query->condition('n.created', [$start, $end], 'BETWEEN');
      
      $query->groupBy('n.uid');
      $query->having('badge_count > :user_badges', [':user_badges' => $user_badges]);
      
      return (int) $query->countQuery()->execute()->fetchField() + 1;
    }
    catch (\Exception $e) {
      return 0;
    }
  }

  /**
   * Gets the top 5 members by badge count for the given year.
   */
  public function getBadgeLeaderboard(int $year, int $limit = 10): array {
    try {
      $start = $year . '-01-01T00:00:00';
      $end = $year . '-12-31T23:59:59';

      $query = $this->database->select('node_field_data', 'n');
      $query->innerJoin('node__field_badge_requested', 'br', 'br.entity_id = n.nid');
      $query->addExpression('COUNT(DISTINCT br.field_badge_requested_target_id)', 'count');
      $query->addField('n', 'uid');
      $query->innerJoin('node__field_badge_status', 'status', 'status.entity_id = n.nid');
      $query->innerJoin('user__roles', 'ur', 'ur.entity_id = n.uid');
      $query->leftJoin('user__field_chargebee_payment_pause', 'pause', 'pause.entity_id = n.uid');
      
      $query->condition('n.type', 'badge_request');
      $query->condition('n.status', 1);
      $query->condition('status.field_badge_status_value', 'active');
      
      $or = $query->orConditionGroup()
        ->condition('ur.roles_target_id', ['member', 'current_member'], 'IN')
        ->condition('pause.field_chargebee_payment_pause_value', 1);
      $query->condition($or);
      
      $query->condition('n.created', [strtotime($start), strtotime($end)], 'BETWEEN');
      $query->condition('n.uid', 1, '>');

      $query->groupBy('n.uid');
      $query->orderBy('count', 'DESC');
      $query->range(0, $limit);
      
      return $this->processLeaderboardResults($query->execute());
    }
    catch (\Exception $e) {
      return [];
    }
  }

  /**
   * Gets the top 5 members by visit days for the given year.
   */
  public function getVisitLeaderboard(int $year, int $limit = 10): array {
    try {
      $query = $this->database->select('access_control_log_field_data', 'acl');
      $query->addExpression('COUNT(DISTINCT DATE(FROM_UNIXTIME(acl.created)))', 'count');
      $query->innerJoin('access_control_log__field_access_request_user', 'user_ref', 'user_ref.entity_id = acl.id');
      $query->innerJoin('user__roles', 'ur', 'ur.entity_id = user_ref.field_access_request_user_target_id');
      $query->leftJoin('user__field_chargebee_payment_pause', 'pause', 'pause.entity_id = user_ref.field_access_request_user_target_id');
      
      $query->addField('user_ref', 'field_access_request_user_target_id', 'uid');
      
      $query->condition('acl.type', 'access_control_request');
      
      $or = $query->orConditionGroup()
        ->condition('ur.roles_target_id', ['member', 'current_member'], 'IN')
        ->condition('pause.field_chargebee_payment_pause_value', 1);
      $query->condition($or);
      
      $query->where('YEAR(FROM_UNIXTIME(acl.created)) = :year', [':year' => $year]);
      $query->condition('user_ref.field_access_request_user_target_id', 1, '>');

      $query->groupBy('user_ref.field_access_request_user_target_id');
      $query->orderBy('count', 'DESC');
      $query->range(0, $limit);
      
      return $this->processLeaderboardResults($query->execute());
    }
    catch (\Exception $e) {
      return [];
    }
  }

  /**
   * Gets the top members by total badges overall (all-time).
   */
  public function getOverallBadgeLeaderboard(int $limit = 10): array {
    try {
      $query = $this->database->select('node_field_data', 'n');
      $query->innerJoin('node__field_badge_requested', 'br', 'br.entity_id = n.nid');
      $query->addExpression('COUNT(DISTINCT br.field_badge_requested_target_id)', 'count');
      $query->addField('n', 'uid');
      $query->innerJoin('node__field_badge_status', 'status', 'status.entity_id = n.nid');
      $query->innerJoin('user__roles', 'ur', 'ur.entity_id = n.uid');
      $query->leftJoin('user__field_chargebee_payment_pause', 'pause', 'pause.entity_id = n.uid');
      
      $query->condition('n.type', 'badge_request');
      $query->condition('n.status', 1);
      $query->condition('status.field_badge_status_value', 'active');
      
      $or = $query->orConditionGroup()
        ->condition('ur.roles_target_id', ['member', 'current_member'], 'IN')
        ->condition('pause.field_chargebee_payment_pause_value', 1);
      $query->condition($or);
      
      $query->condition('n.uid', 1, '>');

      $query->groupBy('n.uid');
      $query->orderBy('count', 'DESC');
      $query->range(0, $limit);
      
      return $this->processLeaderboardResults($query->execute());
    }
    catch (\Exception $e) {
      return [];
    }
  }

  /**
   * Helper to process query results into a leaderboard with names and photos.
   */
  private function processLeaderboardResults($results): array {
    $leaderboard = [];
    $style = \Drupal\image\Entity\ImageStyle::load('thumbnail');
    
    foreach ($results as $record) {
      /** @var \Drupal\user\UserInterface $user */
      $user = $this->entityTypeManager->getStorage('user')->load($record->uid);
      if (!$user) continue;

      $photo_url = NULL;
      $profiles = $this->entityTypeManager->getStorage('profile')->loadByProperties([
        'uid' => $record->uid,
        'type' => 'main',
        'status' => 1,
      ]);
      $profile = reset($profiles);
      if ($profile && $profile->hasField('field_member_photo') && !$profile->get('field_member_photo')->isEmpty()) {
        $file = $profile->get('field_member_photo')->entity;
        if ($file && $style) {
          $photo_url = $style->buildUrl($file->getFileUri());
        }
      }

      $leaderboard[] = [
        'name' => $user->getDisplayName(),
        'count' => (int) $record->count,
        'uid' => (int) $record->uid,
        'photo_url' => $photo_url,
      ];
    }
    return $leaderboard;
  }

  /**
   * Gets the number of appointments for a user in a given year.
   */
  public function getAppointments(int $uid, int $year): int {
    try {
      $start = $year . '-01-01T00:00:00';
      $end = $year . '-12-31T23:59:59';

      $query = $this->entityTypeManager->getStorage('node')->getQuery();
      $query->condition('type', 'appointment');
      $query->condition('uid', $uid);
      $query->condition('status', 1);
      $query->condition('field_appointment_date', [$start, $end], 'BETWEEN');
      $query->accessCheck(FALSE);
      
      return (int) $query->count()->execute();
    }
    catch (\Exception $e) {
      return 0;
    }
  }

  /**
   * Gets the number of appointments hosted by a volunteer in a given year.
   */
  public function getVolunteerHostedAppointments(int $uid, int $year): int {
    try {
      $start = $year . '-01-01T00:00:00';
      $end = $year . '-12-31T23:59:59';

      $query = $this->entityTypeManager->getStorage('node')->getQuery();
      $query->condition('type', 'appointment');
      $query->condition('field_appointment_host', $uid);
      $query->condition('status', 1);
      $query->condition('field_appointment_date', [$start, $end], 'BETWEEN');
      $query->accessCheck(FALSE);

      return (int) $query->count()->execute();
    }
    catch (\Exception $e) {
      return 0;
    }
  }

  /**
   * Gets the rank of a user based on appointments among active members.
   */
  public function getAppointmentRank(int $uid, int $year, int $user_count): int {
    if ($user_count === 0) {
      return 0;
    }

    try {
      $start = $year . '-01-01T00:00:00';
      $end = $year . '-12-31T23:59:59';

      $query = $this->database->select('node_field_data', 'n');
      $query->addExpression('COUNT(DISTINCT n.nid)', 'count');
      $query->innerJoin('node__field_appointment_date', 'd', 'd.entity_id = n.nid');
      $query->innerJoin('user__roles', 'ur', 'ur.entity_id = n.uid');
      $query->leftJoin('user__field_chargebee_payment_pause', 'pause', 'pause.entity_id = n.uid');
      
      $query->condition('n.type', 'appointment');
      $query->condition('n.status', 1);
      
      $or = $query->orConditionGroup()
        ->condition('ur.roles_target_id', ['member', 'current_member'], 'IN')
        ->condition('pause.field_chargebee_payment_pause_value', 1);
      $query->condition($or);
      
      $query->condition('d.field_appointment_date_value', [$start, $end], 'BETWEEN');
      
      $query->groupBy('n.uid');
      $query->having('count > :user_count', [':user_count' => $user_count]);
      
      return (int) $query->countQuery()->execute()->fetchField() + 1;
    }
    catch (\Exception $e) {
      return 0;
    }
  }

  /**
   * Gets the rank of a user based on hosted appointments among active members.
   */
  public function getVolunteerHostedRank(int $uid, int $year, int $user_count): int {
    if ($user_count === 0) {
      return 0;
    }

    try {
      $start = $year . '-01-01T00:00:00';
      $end = $year . '-12-31T23:59:59';

      $query = $this->database->select('node_field_data', 'n');
      $query->addExpression('COUNT(DISTINCT n.nid)', 'count');
      $query->innerJoin('node__field_appointment_date', 'd', 'd.entity_id = n.nid');
      $query->innerJoin('node__field_appointment_host', 'h', 'h.entity_id = n.nid');
      $query->innerJoin('user__roles', 'ur', 'ur.entity_id = h.field_appointment_host_target_id');
      $query->leftJoin('user__field_chargebee_payment_pause', 'pause', 'pause.entity_id = h.field_appointment_host_target_id');

      $query->condition('n.type', 'appointment');
      $query->condition('n.status', 1);
      
      $or = $query->orConditionGroup()
        ->condition('ur.roles_target_id', ['member', 'current_member'], 'IN')
        ->condition('pause.field_chargebee_payment_pause_value', 1);
      $query->condition($or);
      
      $query->condition('d.field_appointment_date_value', [$start, $end], 'BETWEEN');

      $query->groupBy('h.field_appointment_host_target_id');
      $query->having('count > :user_count', [':user_count' => $user_count]);

      return (int) $query->countQuery()->execute()->fetchField() + 1;
    }
    catch (\Exception $e) {
      return 0;
    }
  }

  /**
   * Gets the total number of distinct volunteer hosts in a given year.
   */
  public function getTotalVolunteerHosts(int $year): int {
    try {
      $start = $year . '-01-01T00:00:00';
      $end = $year . '-12-31T23:59:59';

      $query = $this->database->select('node_field_data', 'n');
      $query->innerJoin('node__field_appointment_date', 'd', 'd.entity_id = n.nid');
      $query->innerJoin('node__field_appointment_host', 'h', 'h.entity_id = n.nid');
      $query->innerJoin('user__roles', 'ur', 'ur.entity_id = h.field_appointment_host_target_id');
      $query->leftJoin('user__field_chargebee_payment_pause', 'pause', 'pause.entity_id = h.field_appointment_host_target_id');

      $query->condition('n.type', 'appointment');
      $query->condition('n.status', 1);
      
      $or = $query->orConditionGroup()
        ->condition('ur.roles_target_id', ['member', 'current_member'], 'IN')
        ->condition('pause.field_chargebee_payment_pause_value', 1);
      $query->condition($or);
      
      $query->condition('d.field_appointment_date_value', [$start, $end], 'BETWEEN');

      $query->addExpression('COUNT(DISTINCT h.field_appointment_host_target_id)');
      
      return (int) $query->execute()->fetchField();
    }
    catch (\Exception $e) {
      return 0;
    }
  }

  /**
   * Retrieves member profile information (Goal, Join Year, Seniority).
   */
  public function getMemberProfileInfo(int $uid): array {
    $info = [
      'join_year' => null,
      'tenure_years' => 0,
      'tenure_label' => '',
      'goals' => [],
      'seniority_rank' => 0,
      'total_members' => 0,
      'photo_url' => null,
    ];

    try {
      /** @var \Drupal\user\UserInterface $user */
      $user = $this->entityTypeManager->getStorage('user')->load($uid);
      if (!$user) {
        return $info;
      }

      // 1. Get Total Members (Active only)
      $total_query = $this->database->select('users_field_data', 'u');
      $total_query->condition('u.status', 1);
      $total_query->join('user__roles', 'ur', 'ur.entity_id = u.uid');
      $total_query->leftJoin('user__field_chargebee_payment_pause', 'pause', 'pause.entity_id = u.uid');
      
      $or = $total_query->orConditionGroup()
        ->condition('ur.roles_target_id', ['member', 'current_member'], 'IN')
        ->condition('pause.field_chargebee_payment_pause_value', 1);
      $total_query->condition($or);
      
      $total_query->condition('u.uid', 1, '>');
      $info['total_members'] = (int) $total_query->countQuery()->execute()->fetchField();


      // 2. Load Profile for Goals and specific join date
      $properties = ['uid' => $uid, 'type' => 'main', 'status' => 1];
      $profiles = $this->entityTypeManager->getStorage('profile')->loadByProperties($properties);
      $profile = reset($profiles);

      $join_timestamp = $user->getCreatedTime(); // Default fallback
      
      if ($profile) {
        // Goals
        if ($profile->hasField('field_member_goal') && !$profile->get('field_member_goal')->isEmpty()) {
           foreach ($profile->get('field_member_goal') as $item) {
             $key = $item->value;
             $label = $this->goalsMap[$key] ?? $key;
             $info['goals'][] = [
               'key' => $key,
               'label' => $label,
             ];
          }
        }

        // Areas of Interest
        if ($profile->hasField('field_member_areas_interest') && !$profile->get('field_member_areas_interest')->isEmpty()) {
          foreach ($profile->get('field_member_areas_interest') as $item) {
            if ($term = $item->entity) {
              $info['areas_of_interest'][] = $term->label();
            }
          }
        }
        
        // Join Date from Profile
        if ($profile->hasField('field_member_join_date') && !$profile->get('field_member_join_date')->isEmpty()) {
           $join_date_val = $profile->get('field_member_join_date')->value;
           $join_timestamp = strtotime($join_date_val);
        }

        // Photo Logic
        $file = NULL;
        if ($profile->hasField('field_member_photo') && !$profile->get('field_member_photo')->isEmpty()) {
          $entity = $profile->get('field_member_photo')->entity;
          if ($entity instanceof \Drupal\file\FileInterface) {
            $file = $entity;
          }
          elseif ($entity instanceof \Drupal\media\MediaInterface) {
            // Check common media image fields
            if ($entity->hasField('field_media_image') && !$entity->get('field_media_image')->isEmpty()) {
              $file = $entity->get('field_media_image')->entity;
            } elseif ($entity->hasField('image') && !$entity->get('image')->isEmpty()) {
              $file = $entity->get('image')->entity;
            }
          }
        }

        // Fallback to User Picture
        if (!$file && $user->hasField('user_picture') && !$user->get('user_picture')->isEmpty()) {
           $file = $user->get('user_picture')->entity;
        }

        if ($file instanceof \Drupal\file\FileInterface) {
          $style = \Drupal\image\Entity\ImageStyle::load('medium');
          if ($style) {
            $info['photo_url'] = $style->buildUrl($file->getFileUri());
          } else {
            $info['photo_url'] = \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri());
          }
        }
      }

      // 3. Calculate Tenure and Label
      $now = time();
      $info['join_year'] = date('Y', $join_timestamp);
      $info['tenure_years'] = round(($now - $join_timestamp) / (365 * 24 * 60 * 60), 1);
      
      if ($info['tenure_years'] < 1) {
          $months = round(($now - $join_timestamp) / (30 * 24 * 60 * 60));
          if ($months < 1) {
              $info['tenure_label'] = 'Just joined!';
          } else {
              $info['tenure_label'] = $months . ' Months';
          }
      } else {
          $info['tenure_label'] = $info['tenure_years'] . ' Years';
      }

      // 4. Calculate Rank
      // We calculate rank based on "Start Date".
      // Start Date = field_member_join_date if set, else user.created.
      // We count how many active members have a Start Date strictly earlier than this user's $join_timestamp.
      
      $my_date_str = date('Y-m-d', $join_timestamp);
      
      $query = $this->database->select('users_field_data', 'u');
      $query->addExpression('COUNT(DISTINCT u.uid)');
      $query->join('user__roles', 'ur', 'ur.entity_id = u.uid');
      $query->leftJoin('profile', 'p', "p.uid = u.uid AND p.type = 'main' AND p.status = 1");
      $query->leftJoin('profile__field_member_join_date', 'jd', 'jd.entity_id = p.profile_id');
      $query->leftJoin('user__field_chargebee_payment_pause', 'pause', 'pause.entity_id = u.uid');
      
      $query->condition('u.status', 1);
      
      $or = $query->orConditionGroup()
        ->condition('ur.roles_target_id', ['member', 'current_member'], 'IN')
        ->condition('pause.field_chargebee_payment_pause_value', 1);
      $query->condition($or);

      $query->condition('u.uid', 1, '>'); // Exclude anon/admin(0/1) if needed, usually 1 is admin.
      
      // OR Group for seniority comparison
      $or_group = $query->orConditionGroup();
      
      // Case A: They have a specific Join Date that is earlier than ours
      $grp_has_date = $query->andConditionGroup()
        ->condition('jd.field_member_join_date_value', NULL, 'IS NOT NULL')
        ->condition('jd.field_member_join_date_value', $my_date_str, '<');
      
      // Case B: They rely on created timestamp, which is earlier than ours
      // Note: We only fall back to created if THEY don't have a specific join date.
      $grp_no_date = $query->andConditionGroup()
        ->condition('jd.field_member_join_date_value', NULL, 'IS NULL')
        ->condition('u.created', $join_timestamp, '<');
        
      $or_group->condition($grp_has_date);
      $or_group->condition($grp_no_date);
      
      $query->condition($or_group);
      
      $info['seniority_rank'] = (int) $query->execute()->fetchField() + 1;

    }
    catch (\Exception $e) {
      // Log error
    }

    return $info;
  }
}
