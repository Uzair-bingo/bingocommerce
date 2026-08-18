<?php

namespace BingoCommerce\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * In-request proof that an assignment is executing as part of the current
 * HallStaffProvisioningService creation/recovery workflow (BR-035, invite gate 4).
 *
 * NEVER PERSISTED, NEVER SERIALIZED. This object is not written to usermeta, an
 * option, an audit column, or a database flag, and it is never placed in a token
 * or a request parameter. What survives an HTTP request boundary is the signed
 * HallStaffCreationRetryToken; this context is reconstructed in memory from that
 * token by HallStaffProvisioningService only after successful verification.
 *
 * NEVER AN AUTHORIZATION INPUT. It gates automatic invitation dispatch only, and
 * must not influence capability, eligibility, or assignment decisions. It is
 * deliberately not derived from the bc_identity_type provenance marker, which is
 * a separate and insufficient invitation condition.
 */
final class HallStaffProvisioningContext {

	public function __construct(
		public readonly int $created_user_id,
		public readonly int $network_id,
		public readonly int $blog_id,
		public readonly int $actor_user_id,
		public readonly string $requested_role
	) {}

	/** Does this context authorize invitation dispatch for the given target? */
	public function covers( int $target_user_id ): bool {
		return $this->created_user_id === $target_user_id;
	}
}
