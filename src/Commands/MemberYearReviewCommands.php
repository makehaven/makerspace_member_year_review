<?php

namespace Drupal\makerspace_member_year_review\Commands;

use Drush\Commands\DrushCommands;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\makerspace_member_year_review\Controller\MemberYearReviewController;
use Drupal\user\UserInterface;

/**
 * Drush commands for Makerspace Member Year in Review.
 */
class MemberYearReviewCommands extends DrushCommands {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The controller.
   *
   * @var \Drupal\makerspace_member_year_review\Controller\MemberYearReviewController
   */
  protected $controller;

  /**
   * Constructs a MemberYearReviewCommands object.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, MemberYearReviewController $controller) {
    parent::__construct();
    $this->entityTypeManager = $entity_type_manager;
    $this->controller = $controller;
  }

  /**
   * Pre-calculates and caches Year in Review stats for all active members.
   *
   * @command makerspace-member-year-review:precalculate
   * @aliases yir-pre
   * @param int $year The year to calculate (default 2025).
   */
  public function precalculate($year = 2025) {
    $this->output()->writeln("Starting pre-calculation for $year...");

    // 1. Warm Community Stats Cache (once)
    // We access the protected method via reflection if needed, or if we made it public?
    // Actually, let's just instantiate the controller and if getCommunityYearStats is protected we can't call it easily.
    // BUT, calculateUserStats is public.
    // Let's assume we can trigger community stats calculation via a dummy call or just rely on the first user triggering it if it wasn't cached.
    // However, for a true pre-warm, we should try to call it.
    // Since getCommunityYearStats is protected, let's just proceed with users. The first user calculation might NOT trigger it because calculateUserStats doesn't call getCommunityYearStats.
    // Wait, page() calls both.
    // So to warm EVERYTHING effectively, we should simulate a page build or just cache the heavy parts.
    
    // Let's stick to warming the specific data caches we control.
    
    // Find all users with 'member' or 'current_member' roles
    $query = $this->entityTypeManager->getStorage('user')->getQuery();
    $query->condition('status', 1);
    $query->condition('roles', ['member', 'current_member'], 'IN');
    $query->accessCheck(FALSE);
    $uids = $query->execute();

    $this->output()->writeln("Found " . count($uids) . " active members.");

    $count = 0;
    foreach ($uids as $uid) {
      $user = $this->entityTypeManager->getStorage('user')->load($uid);
      if ($user instanceof UserInterface) {
        $cid = 'makerspace_member_year_review:user_stats:' . $uid . ':' . $year;
        
        // Check if already cached to avoid redundant work if re-running
        if (!\Drupal::cache()->get($cid)) {
             $stats = $this->controller->calculateUserStats($user, $year);
             // Cache for 1 year
             \Drupal::cache()->set($cid, $stats, time() + 31536000, ['user:' . $uid, 'node_list:appointment', 'node_list:badge_request']);
        }
        
        $count++;
        if ($count % 50 == 0) {
          $this->output()->writeln("Processed $count members...");
        }
      }
    }

    $this->output()->writeln("Successfully pre-calculated stats for $count members.");
  }
}
