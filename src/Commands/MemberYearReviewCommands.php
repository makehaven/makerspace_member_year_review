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
        
        // We use a reflection to access the protected calculateUserStats method or just call page()?
        // Actually, let's just use a trick to call the protected method if we can't make it public.
        // For now, I'll make calculateUserStats public in the controller to make this easier.
        
        $stats = $this->controller->calculateUserStats($user, $year);
        \Drupal::cache()->set($cid, $stats, time() + 86400, ['user:' . $uid, 'node_list:appointment', 'node_list:badge_request']);
        
        $count++;
        if ($count % 50 == 0) {
          $this->output()->writeln("Processed $count members...");
        }
      }
    }

    $this->output()->writeln("Successfully pre-calculated stats for $count members.");
  }
}
