/**
 * Add-on gating of the encoding ladder.
 */

/**
 * Whether an option may be enabled on a library and whether it is a locked add-on.
 *
 * The library's own `available_codecs` / `available_resolutions` list is authoritative when it has
 * entries; without it the organization catalog (`available` of GET /encodings) decides. An add-on
 * option the organization has not unlocked is `locked`, so the UI can point at the upgrade.
 *
 * @param {Object} option  Encoding option {key, access, available}.
 * @param {Object} library Library.
 * @param {string} kind    'codecs' | 'resolutions'.
 * @return {{selectable: boolean, locked: boolean}} State.
 */
export function optionState( option, library, kind ) {
	const allow = ( library && library[ `available_${ kind }` ] ) || [];
	const selectable = allow.length
		? allow.includes( option.key )
		: option.available !== false;
	const locked =
		! selectable && option.access === 'addon' && option.available === false;

	return { selectable, locked };
}
