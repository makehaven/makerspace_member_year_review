<?php

use Drupal\user\UserInterface;

$year = 2025;
echo "Starting pre-calculation for $year...\n";

$entityTypeManager = \Drupal::entityTypeManager();
$controller = \Drupal::service('makerspace_member_year_review.stats_controller_helper');

// Find all users with 'member' or 'current_member' roles
$query = $entityTypeManager->getStorage('user')->getQuery();
$query->condition('status', 1);
$query->condition('roles', ['member', 'current_member'], 'IN');
$query->accessCheck(FALSE);
$uids = $query->execute();

echo "Found " . count($uids) . " active members.\n";

$count = 0;
foreach ($uids as $uid) {
  $user = $entityTypeManager->getStorage('user')->load($uid);
  if ($user instanceof UserInterface) {
    $cid = 'makerspace_member_year_review:user_stats:' . $uid . ':' . $year;
    
    // Check if already cached
    if (!\Drupal::cache()->get($cid)) {
         $stats = $controller->calculateUserStats($user, $year);
         // Cache for 1 year (31536000 seconds)
         \Drupal::cache()->set($cid, $stats, time() + 31536000, ['user:' . $uid, 'node_list:appointment', 'node_list:badge_request']);
         echo ".";
    } else {
         echo "s"; // skipped
    }
    
    $count++;
    if ($count % 50 == 0) {
      echo " ($count)\n";
    }
  }
}

echo "\nSuccessfully pre-calculated stats for $count members.\n";

