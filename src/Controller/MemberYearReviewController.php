<?php

namespace Drupal\makerspace_member_year_review\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\makerspace_member_year_review\Service\MemberStatsService;
use Drupal\Core\Access\AccessResult;

/**
 * Controller for Member Year in Review.
 */
class MemberYearReviewController extends ControllerBase {

  /**
   * The stats service.
   *
   * @var \Drupal\makerspace_member_year_review\Service\MemberStatsService
   */
  protected $statsService;

  /**
   * Constructs the controller.
   */
  public function __construct(MemberStatsService $stats_service) {
    $this->statsService = $stats_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('makerspace_member_year_review.stats')
    );
  }

  /**
   * Displays the Year in Review page.
   */
  public function page(UserInterface $user) {
    $year = (int) date('Y');
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
      'visits' => $this->calculateDelta($visit_days, $visit_days_prev),
      'events' => $this->calculateDelta($attendance['count'], $attendance_prev['count']),
      'badges' => $this->calculateDelta(count($badges), count($badges_prev)),
      'loans' => $this->calculateDelta($loans, $loans_prev),
    ];

    return [
      '#theme' => 'member_year_review',
      '#user_name' => $user->getDisplayName(),
      '#year' => $year,
      '#profile' => $profile_info,
      '#deltas' => $deltas,
      '#stats' => [
        'visits' => $visit_days,
        'visits_prev' => $visit_days_prev,
        'events_count' => $attendance['count'],
        'events_list' => $attendance['events'],
        'badges_count' => count($badges),
        'badges_list' => $badges,
        'loans_count' => $loans,
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['user:' . $user->id()],
        'max-age' => 3600, 
      ],
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
   * Access check for the page.
   */
  public function access(AccountInterface $account, UserInterface $user) {
    return AccessResult::allowedIf(
      ($account->id() == $user->id()) || $account->hasPermission('administer users')
    );
  }

}
