<?php
/**
 * Explicit ownership of a bounded core temporary file.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Support\Http;

/** The consumer must close in finally; destructor is only a last-resort cleanup. */
final class FetchedFile {
	/**
	 * Transfer a validated temporary file to its consumer.
	 *
	 * @param string $path Core-created temporary path.
	 * @param int    $max Maximum bytes.
	 * @throws \InvalidArgumentException For an invalid byte budget.
	 */
	public function __construct( private string $path, private int $max ) {
		if ( $max < 1 || $max > 67108864 ) {
			throw new \InvalidArgumentException( 'Invalid remote byte budget.' ); } }
	/**
	 * Read at most the proved byte budget without forwarding a server path.
	 *
	 * @return string
	 * @throws \RuntimeException For unavailable or changed files.
	 */
	public function bytes(): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Bounded read of a core-created temporary file.
		$data = file_get_contents( $this->path, false, null, 0, max( 1, $this->max + 1 ) );
		if ( false === $data || '' === $data || strlen( $data ) > $this->max ) {
			throw new \RuntimeException( 'Remote data is unavailable.' ); }
		return $data;
	}
	/** Delete only the owned core temporary file, idempotently. */
	public function close(): void {
		if ( '' !== $this->path ) {
			wp_delete_file( $this->path );
			$this->path = ''; } }
	/** Last-resort ownership cleanup. */
	public function __destruct() {
		$this->close(); }
}
