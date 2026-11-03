-- Existing accounts are conservatively claimed. Never infer availability from
-- an .invalid email, a disabled account, or an application awaiting review.
-- Only trusted provisioning data may explicitly mark a credential-free,
-- inactive student record unclaimed. Public registration cannot set this flag.
ALTER TABLE student_applications
 ADD COLUMN IF NOT EXISTS account_claim_state VARCHAR(12) NOT NULL DEFAULT 'claimed'
 CHECK (account_claim_state IN ('unclaimed','claimed'));
