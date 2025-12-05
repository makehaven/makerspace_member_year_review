<?php

namespace Drupal\makerspace_member_year_review\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;

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
   * Constructs a MemberStatsService object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, Connection $database) {
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
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
        $badges[] = $node->getTitle();
      }
      return $badges;
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
    ];

    try {
      /** @var \Drupal\user\UserInterface $user */
      $user = $this->entityTypeManager->getStorage('user')->load($uid);
      if (!$user) {
        return $info;
      }

      // 1. Get Total Members (Always)
      $total_query = $this->database->select('profile', 'p');
      $total_query->condition('p.type', 'main');
      $total_query->condition('p.status', 1);
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
        
        // Join Date from Profile
        if ($profile->hasField('field_member_join_date') && !$profile->get('field_member_join_date')->isEmpty()) {
           $join_date_val = $profile->get('field_member_join_date')->value;
           $join_timestamp = strtotime($join_date_val);
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
      // Compare join_timestamp against all other profiles' field_member_join_date (or fallback?)
      // Consistent ranking relies on everyone having the field. If accurate ranking is needed, we query the field.
      // If we use $join_timestamp (which might be user->created), we compare it to value in DB.
      $join_date_compare = date('Y-m-d', $join_timestamp);

      $query = $this->database->select('profile__field_member_join_date', 'jd');
      $query->condition('jd.field_member_join_date_value', $join_date_compare, '<');
      $query->condition('jd.deleted', 0);
      $query->join('profile', 'p', 'p.profile_id = jd.entity_id');
      $query->condition('p.type', 'main');
      $query->condition('p.status', 1);
      
      // Add 1 because they are the Nth+1 person
      $info['seniority_rank'] = (int) $query->countQuery()->execute()->fetchField() + 1;

    }
    catch (\Exception $e) {
      // Log error
    }

    return $info;
  }
}
