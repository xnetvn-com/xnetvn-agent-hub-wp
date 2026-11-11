<?php
/**
 * Exact-controller policy fixture.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Tests\Integration;

/** Exact-controller policy must refuse even a subclass of the default core controller. */
final class FixtureCustomPostsController extends \WP_REST_Posts_Controller {}
