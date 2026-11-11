/* global wp, xnetvnAgentHubConfig */
(function () {
	'use strict';
	const __ = wp.i18n.__;
	const config = xnetvnAgentHubConfig;
	const api = (path, options = {}) => wp.apiFetch(Object.assign({}, options, {
		path: config.namespace + path,
		headers: Object.assign({}, options.headers || {}, { 'X-WP-Nonce': config.nonce }),
	}));
	const node = (tag, text, attributes = {}) => {
		const element = document.createElement(tag);
		if (text !== undefined && text !== null) element.textContent = String(text);
		Object.keys(attributes).forEach(name => element.setAttribute(name, attributes[name]));
		return element;
	};
	const clear = element => { while (element.firstChild) element.removeChild(element.firstChild); };
	const date = value => value ? new Date(value).toLocaleString() : __('Never', 'xnetvn-agent-hub-wp');
	const highRisk = tier => tier === 'destructive' || tier === 'admin';
	const values = element => element.value.split(/[\r\n,]+/).map(value => value.trim()).filter(Boolean);
	const isSessionError = error => {
		if (!error || typeof error !== 'object') return false;
		const status = error.status || (error.data && error.data.status);
		return status === 401 || status === 403;
	};

	/**
	 * Copy a readonly endpoint value without ever touching secrets.
	 *
	 * Only plain readonly inputs qualify: secret fields opt out through the
	 * data-secret marker. Feedback always goes through the live region.
	 *
	 * @param {HTMLInputElement} input Field holding the readonly value.
	 * @param {Element} messageRoot Live region announcing the outcome.
	 * @return {Promise<boolean>} Whether the Clipboard API accepted the value.
	 */
	const copyReadonlyValue = (input, messageRoot) => {
		const announce = message => {
			if (messageRoot) messageRoot.textContent = message;
			wp.a11y.speak(message, 'polite');
		};
		if (!input || input.hasAttribute('data-secret') || input.disabled || input.value === '') {
			return Promise.resolve(false);
		}
		const fallback = () => {
			input.focus();
			input.select();
			announce(__('Copy was unavailable. Use your keyboard copy command on the selected value.', 'xnetvn-agent-hub-wp'));
			return false;
		};
		if (navigator.clipboard && navigator.clipboard.writeText) {
			return navigator.clipboard.writeText(input.value).then(() => {
				announce(__('Copied.', 'xnetvn-agent-hub-wp'));
				return true;
			}).catch(fallback);
		}
		return Promise.resolve(fallback());
	};
	if (typeof window !== 'undefined' && !window.xnetvnCopyReadonlyValue) {
		window.xnetvnCopyReadonlyValue = copyReadonlyValue;
	}

	/**
	 * Keep the native Settings API submission safe.
	 *
	 * The form still posts to options.php with the WordPress nonce and body:
	 * saving is never turned into AJAX, never retried and no value is rewritten.
	 * The button is disabled only after the browser accepts the form, so an
	 * invalid field keeps its message, its focus and an enabled Save button.
	 */
	const initSettingsForm = () => {
		const form = document.querySelector('form.xnetvn-agent-hub');
		const saveButton = form ? form.querySelector('input[type="submit"]') : null;
		if (form) {
			const firstInvalid = () => Array.prototype.slice.call(form.elements)
				.find(element => element.willValidate && !element.checkValidity()) || null;
			form.addEventListener('submit', event => {
				if (form.dataset.xnetvnSubmitting === '1') {
					event.preventDefault();
					return;
				}
				if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
					event.preventDefault();
					const invalid = firstInvalid();
					if (invalid) {
						invalid.focus();
					}
					wp.a11y.speak(__('Settings were not sent. Check the highlighted field.', 'xnetvn-agent-hub-wp'), 'assertive');
					return;
				}
				form.dataset.xnetvnSubmitting = '1';
				if (saveButton) {
					saveButton.disabled = true;
				}
			});
			// A rejected save, the back button or a restored tab returns the browser
			// to this page; the control must be usable again without a reload.
			window.addEventListener('pageshow', () => {
				delete form.dataset.xnetvnSubmitting;
				if (saveButton) {
					saveButton.disabled = false;
				}
			});
		}
		const report = document.querySelector('.xnetvn-settings-errors');
		if (report && report.textContent.trim() !== '') {
			report.focus();
		}
	};
	initSettingsForm();

	document.querySelectorAll('.xnetvn-agent-hub[data-view]').forEach(root => {
		const view = root.dataset.view;
		const status = root.querySelector('.xnetvn-status');
		const loadingEl = root.querySelector('[data-loading]');
		const errorEl = root.querySelector('[data-error]');
		const errorText = root.querySelector('[data-error-text]');
		const emptyEl = root.querySelector('[data-empty]');
		const retryButton = root.querySelector('[data-retry]');
		const pagerButtons = () => [root.querySelector('[data-prev]'), root.querySelector('[data-next]')].filter(Boolean);
		const say = message => {
			status.textContent = message;
			wp.a11y.speak(message, 'polite');
		};
		const fail = () => say(__('The operation could not complete. Check the fields and your permissions. Refresh the page if your session has expired.', 'xnetvn-agent-hub-wp'));
		let seq = 0;
		let busy = false;
		let page = 1;
		let lastGood = 1;
		let lastTotal = 0;
		let lastSize = 20;
		let failedPage = null;
		const beginRead = () => {
			seq += 1;
			busy = true;
			root.setAttribute('aria-busy', 'true');
			if (loadingEl) loadingEl.hidden = false;
			if (errorEl) errorEl.hidden = true;
			pagerButtons().forEach(button => { button.disabled = true; });
			if (retryButton) retryButton.disabled = true;
			return seq;
		};
		const settleRead = (id, error) => {
			if (id !== seq) return false;
			busy = false;
			root.setAttribute('aria-busy', 'false');
			if (loadingEl) loadingEl.hidden = true;
			if (retryButton) retryButton.disabled = false;
			if (error) showReadError(error);
			return true;
		};
		const isCurrent = id => id === seq;
		const showReadError = error => {
			const message = isSessionError(error)
				? __('Your session may have expired. Reload the page and log in again.', 'xnetvn-agent-hub-wp')
				: __('The operation could not complete. Check the fields and your permissions. Refresh the page if your session has expired.', 'xnetvn-agent-hub-wp');
			if (errorText) errorText.textContent = message;
			if (errorEl) errorEl.hidden = false;
			say(message);
		};
		const paging = (total, size) => {
			const previous = root.querySelector('[data-prev]');
			const next = root.querySelector('[data-next]');
			if (!previous || !next) return;
			lastTotal = total;
			lastSize = size;
			previous.disabled = busy || page <= 1;
			next.disabled = busy || page * size >= total;
			// translators: %d: current result page number.
			root.querySelector('[data-page]').textContent = wp.i18n.sprintf(__('Page %d', 'xnetvn-agent-hub-wp'), page);
		};
		const restorePaging = () => {
			paging(lastTotal, lastSize);
		};
		const toggleEmpty = isEmpty => {
			if (emptyEl) emptyEl.hidden = !isEmpty;
		};
		const setupPaging = reload => {
			const previous = root.querySelector('[data-prev]');
			const next = root.querySelector('[data-next]');
			if (!previous || !next) return;
			previous.addEventListener('click', () => {
				if (busy || page <= 1) return;
				failedPage = null;
				page -= 1;
				reload().catch(() => {});
			});
			next.addEventListener('click', () => {
				if (busy) return;
				failedPage = null;
				page += 1;
				reload().catch(() => {});
			});
		};
		const setupRetry = reload => {
			if (!retryButton) return;
			retryButton.addEventListener('click', () => {
				if (busy) return;
				page = failedPage !== null ? failedPage : page;
				failedPage = null;
				reload().catch(() => {});
			});
		};

		if (view === 'keys') {
			const secretDialog = root.querySelector('[data-secret-dialog]');
			const secretInput = root.querySelector('[data-secret]');
			const actionDialog = root.querySelector('[data-action-dialog]');
			const actionPassword = root.querySelector('#xnetvn-action-password');
			const createButton = root.querySelector('[data-create]');
			const stateFilter = root.querySelector('[data-filter="state"]');
			const ownerFilter = root.querySelector('[data-filter="owner"]');
			const applyButton = root.querySelector('[data-apply]');
			const clearButton = root.querySelector('[data-clear]');
			const allOwners = root.dataset.all === '1';
			let returnFocus = null;
			let pendingAction = null;
			let mutationPending = false;
			let quietRefresh = false;
			let filters = { state: 'all', owner: '' };
			const restoreFocus = () => {
				if (returnFocus && returnFocus.isConnected && !returnFocus.disabled) returnFocus.focus();
				else if (createButton && !createButton.disabled) createButton.focus();
				else { root.tabIndex = -1; root.focus(); }
			};
			/**
			 * Drop every credential value this root holds.
			 *
			 * Runs on dialog close, on page teardown and after each mutation, so no
			 * secret or password survives in the DOM.
			 */
			const clearCredentials = () => {
				secretInput.value = '';
				actionPassword.value = '';
			};
			secretDialog.addEventListener('close', () => {
				clearCredentials();
				restoreFocus();
			});
			root.querySelector('[data-saved]').addEventListener('click', () => secretDialog.close());
			root.querySelector('[data-copy]').addEventListener('click', async () => {
				try {
					if (!navigator.clipboard || !navigator.clipboard.writeText) throw new Error('clipboard unavailable');
					await navigator.clipboard.writeText(secretInput.value);
					say(__('Key copied. Store it securely before closing this window.', 'xnetvn-agent-hub-wp'));
				} catch (error) {
					secretInput.focus();
					secretInput.select();
					say(__('Copy was unavailable. Use your keyboard copy command on the selected key.', 'xnetvn-agent-hub-wp'));
				}
			});
			const showSecret = result => {
				secretInput.value = result.secret;
				delete result.secret;
				secretDialog.showModal();
				secretInput.focus();
				secretInput.select();
				wp.a11y.speak(__('Your new key is shown once. Save it now.', 'xnetvn-agent-hub-wp'), 'polite');
			};
			actionDialog.addEventListener('close', () => {
				clearCredentials();
				pendingAction = null;
				if (!secretDialog.open) restoreFocus();
			});
			window.addEventListener('pagehide', clearCredentials);
			root.querySelector('[data-cancel]').addEventListener('click', () => actionDialog.close());
			/**
			 * Lock every row and dialog action while a mutation is in flight.
			 *
			 * @param {boolean} value Locked while true.
			 */
			const lockActions = value => {
				root.querySelectorAll('[data-mutation], [data-confirm]').forEach(control => { control.disabled = value; });
			};
			/**
			 * Run one mutation at a time for this root.
			 *
			 * The lock starts before the request and is released only in the
			 * finally block, so a repeated click can never send a second request.
			 *
			 * @param {Function} operation Request body executed once.
			 * @return {Promise<void>} Resolves after the lock is released.
			 */
			const runMutation = async operation => {
				if (mutationPending) return;
				mutationPending = true;
				lockActions(true);
				try {
					await operation();
				} finally {
					mutationPending = false;
					lockActions(false);
				}
			};
			const uncertainResult = () => say(__('The key change finished with an uncertain result. The list will reload; check it before creating a replacement key.', 'xnetvn-agent-hub-wp'));
			const refreshFailed = () => say(__('Metadata refresh failed. The key change was saved; reload the page to see the current list.', 'xnetvn-agent-hub-wp'));
			/**
			 * Report a failed mutation without ever guessing the outcome.
			 *
			 * A server answer means the result is known; a lost response is
			 * reported as uncertain and never retried automatically.
			 *
			 * @param {Object} error Rejected request error.
			 */
			const reportMutation = error => {
				const status = error && typeof error === 'object' ? (error.status || (error.data && error.data.status)) : null;
				if (typeof status === 'number') {
					fail();
					return;
				}
				uncertainResult();
			};
			/** Keep only the published listing filters the controls can express. */
			const readFilters = () => {
				const state = stateFilter && stateFilter.value ? stateFilter.value : 'all';
				const owner = ownerFilter && /^[1-9][0-9]*$/.test(ownerFilter.value.trim()) ? ownerFilter.value.trim() : '';
				filters = { state, owner };
			};
			/** Apply the current filters and return to the first result page. */
			const applyFilters = () => {
				readFilters();
				page = 1;
				failedPage = null;
				load().catch(() => {});
			};
			if (stateFilter) stateFilter.addEventListener('change', applyFilters);
			if (applyButton) applyButton.addEventListener('click', applyFilters);
			if (clearButton) clearButton.addEventListener('click', () => {
				if (stateFilter) stateFilter.value = 'all';
				if (ownerFilter) ownerFilter.value = '';
				applyFilters();
			});
			const ask = (kind, key, trigger) => {
				if (mutationPending) return;
				returnFocus = trigger;
				pendingAction = { kind, key };
				const passwordRequired = kind === 'rotate' && highRisk(key.tier_max);
				root.querySelector('[data-action-password-group]').hidden = !passwordRequired;
				actionPassword.required = passwordRequired;
				actionPassword.value = '';
				if (kind === 'rotate') {
					// translators: %s: key display name.
					root.querySelector('[data-action-message]').textContent = wp.i18n.sprintf(__('Rotate "%s"? A new key inherits the same scope. The old key expires within 24 hours.', 'xnetvn-agent-hub-wp'), key.name);
				} else {
					// translators: %s: key display name.
					root.querySelector('[data-action-message]').textContent = wp.i18n.sprintf(__('Revoke "%s"? Future requests with this key will be denied.', 'xnetvn-agent-hub-wp'), key.name);
				}
				actionDialog.showModal();
				if (passwordRequired) actionPassword.focus();
				else root.querySelector('[data-confirm]').focus();
			};
			/**
			 * Read one page of the listing with the current filters.
			 *
			 * After a successful mutation the read only refreshes metadata, so a
			 * failed refresh never reports the mutation itself as failed.
			 *
			 * @return {Promise<void>} Resolves after the table is replaced.
			 */
			const load = async () => {
				const quiet = quietRefresh;
				quietRefresh = false;
				const id = beginRead();
				const wanted = page;
				const previous = lastGood;
				const failRead = error => {
					if (!settleRead(id, quiet ? null : error)) return;
					page = previous;
					failedPage = wanted;
					restorePaging();
				};
				let response;
				try {
					const query = new URLSearchParams({ page: String(wanted), per_page: '20', state: filters.state });
					if (allOwners && !filters.owner) query.set('all', 'true');
					else query.set('user_id', allOwners && filters.owner ? filters.owner : String(root.dataset.userId));
					response = await api('/keys?' + query.toString(), { parse: false });
					if (!response.ok) {
						throw { status: response.status };
					}
				} catch (error) {
					failRead(error);
					throw error;
				}
				let keys;
				try {
					keys = await response.json();
				} catch (error) {
					failRead(error);
					throw error;
				}
				if (!isCurrent(id)) return;
				if (!Array.isArray(keys)) {
					const typeError = new Error('invalid keys response');
					failRead(typeError);
					throw typeError;
				}
				const table = root.querySelector('[data-keys]');
				clear(table);
				keys.forEach(key => {
					const row = node('tr');
					[key.name, key.key_id, key.user_id, key.tier_max, key.toolsets.join(', '), date(key.created_at), date(key.last_used_at), date(key.expires_at), key.status].forEach(value => row.appendChild(node('td', value)));
					const actions = node('td');
					if (key.status === 'active') {
						if (Number(key.user_id) === Number(config.userId)) {
							// translators: %s: key display name.
							const rotateLabel = wp.i18n.sprintf(__('Rotate key: %s', 'xnetvn-agent-hub-wp'), key.name);
							const rotate = node('button', __('Rotate key', 'xnetvn-agent-hub-wp'), { type: 'button', class: 'button', 'data-mutation': 'rotate', 'aria-label': rotateLabel });
							rotate.addEventListener('click', () => ask('rotate', key, rotate));
							actions.appendChild(rotate);
						}
						// translators: %s: key display name.
						const revokeLabel = wp.i18n.sprintf(__('Revoke key: %s', 'xnetvn-agent-hub-wp'), key.name);
						const revoke = node('button', __('Revoke key', 'xnetvn-agent-hub-wp'), { type: 'button', class: 'button', 'data-mutation': 'revoke', 'aria-label': revokeLabel });
						revoke.addEventListener('click', () => ask('revoke', key, revoke));
						actions.appendChild(revoke);
					}
					row.appendChild(actions);
					table.appendChild(row);
				});
				if (!keys.length) { const row = node('tr'); row.appendChild(node('td', __('No keys found.', 'xnetvn-agent-hub-wp'), { colspan: '10' })); table.appendChild(row); }
				toggleEmpty(keys.length === 0);
				const total = Number(response.headers.get('X-WP-Total'));
				lastGood = wanted;
				failedPage = null;
				settleRead(id);
				paging(total, 20);
				lockActions(mutationPending);
			};
			root.querySelector('[data-confirm]').addEventListener('click', () => {
				if (mutationPending || !pendingAction || !actionPassword.reportValidity()) return;
				const operation = pendingAction;
				const data = highRisk(operation.key.tier_max) && operation.kind === 'rotate' ? { current_password: actionPassword.value } : {};
				actionPassword.value = '';
				actionDialog.close();
				runMutation(async () => {
					try {
						if (operation.kind === 'rotate') {
							const result = await api('/keys/' + operation.key.id + '/rotate', { method: 'POST', data });
							showSecret(result);
						} else {
							await api('/keys/' + operation.key.id, { method: 'DELETE' });
							say(__('Key revoked.', 'xnetvn-agent-hub-wp'));
						}
						quietRefresh = true;
						try {
							await load();
						} catch (error) { refreshFailed(); }
					} catch (error) {
						reportMutation(error);
						load().catch(() => {});
					} finally {
						delete data.current_password;
						actionPassword.value = '';
					}
				});
			});
			if (createButton) {
				createButton.disabled = false;
				const group = root.querySelector('[data-create-group]');
				const field = name => group.querySelector('[data-field="' + name + '"]');
				const updateTier = () => {
					const required = highRisk(field('tier_max').value);
					group.querySelector('[data-password-group]').hidden = !required;
					field('current_password').required = required;
					if (!required) field('current_password').value = '';
				};
				field('tier_max').addEventListener('change', updateTier);
				updateTier();
				createButton.addEventListener('click', () => {
					if (mutationPending) return;
					if (!Array.from(group.querySelectorAll('input,select,textarea')).every(element => element.reportValidity())) return;
					returnFocus = createButton;
					const data = {
						name: field('name').value,
						expires_in_days: Number(field('expires_in_days').value),
						tier_max: field('tier_max').value,
						toolsets: Array.from(group.querySelectorAll('[data-toolset]:checked')).map(element => element.value),
						ip_allowlist: values(field('ip_allowlist')),
						ability_deny: Array.from(field('ability_deny').selectedOptions).map(option => option.value),
					};
					// The password leaves the DOM before the request is issued.
					const password = highRisk(data.tier_max) ? field('current_password').value : '';
					field('current_password').value = '';
					if (highRisk(data.tier_max)) data.current_password = password;
					runMutation(async () => {
						createButton.disabled = true;
						try {
							const result = await api('/keys', { method: 'POST', data });
							createButton.disabled = false;
							showSecret(result);
							field('name').value = '';
							quietRefresh = true;
							try {
								await load();
							} catch (error) { refreshFailed(); }
						} catch (error) {
							createButton.disabled = false;
							reportMutation(error);
							load().catch(() => {});
						} finally {
							createButton.disabled = false;
							field('current_password').value = '';
							delete data.current_password;
						}
					});
				});
			}
			setupPaging(load);
			setupRetry(load);
			load().catch(() => {});
		} else if (view === 'activity') {
			const ownerFilter = root.querySelector('[data-filter="owner"]');
			const keyFilter = root.querySelector('[data-filter="key_id"]');
			const abilityFilter = root.querySelector('[data-filter="ability"]');
			const outcomeFilter = root.querySelector('[data-filter="outcome"]');
			const afterFilter = root.querySelector('[data-filter="after"]');
			const beforeFilter = root.querySelector('[data-filter="before"]');
			const applyButton = root.querySelector('[data-apply]');
			const clearButton = root.querySelector('[data-clear]');
			const timezoneEl = root.querySelector('[data-timezone]');
			let filters = { owner: '', key_id: '', ability: '', outcome: 'all', after: '', before: '' };
			/**
			 * Format an audit timestamp in the browser locale with its zone.
			 *
			 * Anything unreadable reads Unavailable, exactly like other facts.
			 *
			 * @param {string} value UTC timestamp.
			 * @return {string} Localized timestamp with zone.
			 */
			const activityDate = value => {
				try {
					const moment = new Date(String(value).replace(' ', 'T') + 'Z');
					if (Number.isNaN(moment.getTime())) {
						return __('Unavailable', 'xnetvn-agent-hub-wp');
					}
					return moment.toLocaleString(undefined, { timeZoneName: 'short' });
				} catch (error) {
					return __('Unavailable', 'xnetvn-agent-hub-wp');
				}
			};
			/**
			 * Convert a datetime-local value to UTC ISO for the request.
			 *
			 * @param {string} value Local datetime without zone.
			 * @return {string} UTC ISO string or empty when unreadable.
			 */
			const toUtcIso = value => {
				if (!value) {
					return '';
				}
				const moment = new Date(value);
				if (Number.isNaN(moment.getTime())) {
					return '';
				}
				return moment.toISOString();
			};
			if (timezoneEl) {
				try {
					const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
					timezoneEl.textContent = zone
						? __('Times use your browser time zone:', 'xnetvn-agent-hub-wp') + ' ' + zone
						: __('Times are converted to UTC before the request.', 'xnetvn-agent-hub-wp');
				} catch (error) {
					timezoneEl.textContent = __('Times are converted to UTC before the request.', 'xnetvn-agent-hub-wp');
				}
			}
			/** Keep only the published audit filters the controls can express. */
			const readFilters = () => {
				const owner = ownerFilter && /^[1-9][0-9]*$/.test(ownerFilter.value.trim()) ? ownerFilter.value.trim() : '';
				const keyId = keyFilter && /^[a-z2-7]{12}$/.test(keyFilter.value.trim()) ? keyFilter.value.trim() : '';
				const ability = abilityFilter && /^xnetvn\/[a-z][a-z0-9-]{0,120}$/.test(abilityFilter.value.trim()) ? abilityFilter.value.trim() : '';
				const outcome = outcomeFilter && ['all', 'success', 'denied', 'error', 'invalid_input', 'rate_limited', 'auth_failed'].includes(outcomeFilter.value) ? outcomeFilter.value : 'all';
				const after = afterFilter ? toUtcIso(afterFilter.value) : '';
				const before = beforeFilter ? toUtcIso(beforeFilter.value) : '';
				filters = { owner, key_id: keyId, ability, outcome, after, before };
			};
			/** Apply the current filters and return to the first result page. */
			const applyFilters = () => {
				readFilters();
				if (filters.after && filters.before && new Date(filters.after) > new Date(filters.before)) {
					say(__('The From time must be before the To time. Adjust the range and try again.', 'xnetvn-agent-hub-wp'));
					return;
				}
				page = 1;
				failedPage = null;
				load().catch(() => {});
			};
			if (applyButton) {
				applyButton.addEventListener('click', applyFilters);
			}
			if (outcomeFilter) {
				outcomeFilter.addEventListener('change', applyFilters);
			}
			if (clearButton) {
				clearButton.addEventListener('click', () => {
					if (ownerFilter) {
						ownerFilter.value = '';
					}
					if (keyFilter) {
						keyFilter.value = '';
					}
					if (abilityFilter) {
						abilityFilter.value = '';
					}
					if (outcomeFilter) {
						outcomeFilter.value = 'all';
					}
					if (afterFilter) {
						afterFilter.value = '';
					}
					if (beforeFilter) {
						beforeFilter.value = '';
					}
					applyFilters();
				});
			}
			const load = async () => {
				const id = beginRead();
				const wanted = page;
				const previous = lastGood;
				const failRead = error => {
					if (!settleRead(id, error)) {
						return;
					}
					page = previous;
					failedPage = wanted;
					restorePaging();
				};
				let query;
				try {
					query = new URLSearchParams({ page: String(wanted), per_page: '20' });
					if (filters.owner) {
						query.set('user_id', filters.owner);
					} else {
						query.set('all', 'true');
					}
					if (filters.key_id) {
						query.set('key_id', filters.key_id);
					}
					if (filters.ability) {
						query.set('ability', filters.ability);
					}
					if (filters.outcome && filters.outcome !== 'all') {
						query.set('outcome', filters.outcome);
					}
					if (filters.after) {
						query.set('after', filters.after);
					}
					if (filters.before) {
						query.set('before', filters.before);
					}
				} catch (error) {
					failRead(error);
					throw error;
				}
				let result;
				try {
					result = await api('/audit?' + query.toString());
				} catch (error) {
					failRead(error);
					throw error;
				}
				if (!isCurrent(id)) {
					return;
				}
				if (!result || !Array.isArray(result.items) || typeof result.total !== 'number' || typeof result.per_page !== 'number') {
					const typeError = new Error('invalid activity response');
					failRead(typeError);
					throw typeError;
				}
				const totalPages = Math.max(1, Math.ceil(result.total / result.per_page));
				if (wanted > totalPages && result.total > 0) {
					page = totalPages;
					if (isCurrent(id)) {
						settleRead(id);
					}
					load().catch(() => {});
					return;
				}
				const table = root.querySelector('[data-activity]');
				clear(table);
				result.items.forEach(item => {
					const row = node('tr');
					row.appendChild(node('td', activityDate(item.occurred_at)));
					row.appendChild(node('td', item.user_id || __('Anonymous', 'xnetvn-agent-hub-wp')));
					row.appendChild(node('td', item.ability || __('Unavailable', 'xnetvn-agent-hub-wp')));
					const outcomeCell = node('td');
					const badge = node('span', item.outcome, { class: 'xnetvn-outcome xnetvn-outcome-' + String(item.outcome).replace(/[^a-z_]/g, '') });
					outcomeCell.appendChild(badge);
					row.appendChild(outcomeCell);
					row.appendChild(node('td', item.transport));
					table.appendChild(row);
				});
				if (!result.items.length) { const row = node('tr'); row.appendChild(node('td', __('No activity found.', 'xnetvn-agent-hub-wp'), { colspan: '5' })); table.appendChild(row); }
				toggleEmpty(result.items.length === 0);
				lastGood = wanted;
				failedPage = null;
				settleRead(id);
				paging(result.total, result.per_page);
			};
			setupPaging(load);
			setupRetry(load);
			load().catch(() => {});
		} else if (view === 'overview') {
			/**
			 * Render one status fact without ever guessing a missing value.
			 *
			 * A null, undefined or empty fact reads Unavailable; booleans read
			 * Yes or No; every other value keeps its own text through textContent.
			 */
			const formatFact = value => {
				if (value === null || value === undefined || value === '') {
					return __('Unavailable', 'xnetvn-agent-hub-wp');
				}
				if (typeof value === 'boolean') {
					return value ? __('Yes', 'xnetvn-agent-hub-wp') : __('No', 'xnetvn-agent-hub-wp');
				}
				return value;
			};
			/**
			 * Render the next cleanup time in the browser locale with its zone.
			 *
			 * Anything that is not a readable date reads Unavailable, exactly
			 * like any other missing fact.
			 */
			const formatCleanup = value => {
				if (value === null || value === undefined || value === '') {
					return __('Unavailable', 'xnetvn-agent-hub-wp');
				}
				const moment = new Date(value);
				if (Number.isNaN(moment.getTime())) {
					return __('Unavailable', 'xnetvn-agent-hub-wp');
				}
				return moment.toLocaleString(undefined, { timeZoneName: 'short' });
			};
			const connectionList = root.querySelector('[data-status-connection]');
			const siteList = root.querySelector('[data-status-site]');
			const connectionHelp = root.querySelector('[data-connection-help]');
			const load = () => {
				const id = beginRead();
				return api('/status').then(result => {
					if (!isCurrent(id)) return;
					const facts = result && typeof result === 'object' ? result : {};
					const adapter = facts.adapter && typeof facts.adapter === 'object' ? facts.adapter : {};
					const connection = [
						[__('HTTPS', 'xnetvn-agent-hub-wp'), facts.https],
						[__('Transport permitted', 'xnetvn-agent-hub-wp'), facts.transport_allowed],
						[__('Adapter version', 'xnetvn-agent-hub-wp'), adapter.version],
					];
					const site = [
						[__('Registered abilities', 'xnetvn-agent-hub-wp'), facts.ability_count],
						[__('Stored keys', 'xnetvn-agent-hub-wp'), facts.key_count],
						[__('Database version', 'xnetvn-agent-hub-wp'), facts.db_version],
					];
					clear(connectionList);
					clear(siteList);
					connection.forEach(([label, value]) => {
						connectionList.appendChild(node('dt', label));
						connectionList.appendChild(node('dd', formatFact(value)));
					});
					site.forEach(([label, value]) => {
						siteList.appendChild(node('dt', label));
						siteList.appendChild(node('dd', formatFact(value)));
					});
					siteList.appendChild(node('dt', __('Next audit cleanup', 'xnetvn-agent-hub-wp')));
					siteList.appendChild(node('dd', formatCleanup(facts.audit_cron_next)));
					// A connection that is not ready points at Help; nothing here
					// repairs security settings or retries the read on its own.
					const ready = facts.https === true && facts.transport_allowed === true;
					if (connectionHelp) connectionHelp.hidden = ready;
					toggleEmpty(false);
					settleRead(id);
				}).catch(error => {
					if (connectionHelp) connectionHelp.hidden = false;
					settleRead(id, error);
				});
			};
			const endpointInput = root.querySelector('#xnetvn-endpoint');
			const copyEndpoint = root.querySelector('[data-copy-endpoint]');
			if (endpointInput && copyEndpoint) {
				copyEndpoint.addEventListener('click', () => {
					copyReadonlyValue(endpointInput, status);
				});
			}
			const checkButton = root.querySelector('[data-check-header]');
			const headerResult = root.querySelector('[data-header-result]');
			let headerPending = false;
			if (checkButton) {
				checkButton.addEventListener('click', async () => {
					// One diagnostic GET per click: a repeated click while the
					// request is in flight is ignored, never queued.
					if (headerPending) return;
					headerPending = true;
					checkButton.disabled = true;
					root.setAttribute('aria-busy', 'true');
					try {
						const result = await api('/diagnostics/auth-header', { headers: { Authorization: 'Bearer redacted' } });
						const message = result.authorization_present || result.redirect_authorization_present
							? __('Authorization header received by this host.', 'xnetvn-agent-hub-wp')
							: __('Authorization header was not received. Check your web server or proxy configuration.', 'xnetvn-agent-hub-wp');
						headerResult.textContent = message;
						say(message);
					} catch (error) { fail(); } finally {
						headerPending = false;
						checkButton.disabled = false;
						if (!busy) root.setAttribute('aria-busy', 'false');
					}
				});
			}
			setupRetry(load);
			load();
		} else if (view === 'help') {
			// Static guidance: the only interaction copies the readonly
			// endpoint through the shared helper, never a secret.
			const endpointInput = root.querySelector('#xnetvn-help-endpoint');
			const copyEndpoint = root.querySelector('[data-copy-endpoint]');
			const messageRoot = root.querySelector('[data-help-status]') || status;
			if (endpointInput && copyEndpoint) {
				copyEndpoint.addEventListener('click', () => {
					copyReadonlyValue(endpointInput, messageRoot);
				});
			}
		} else if (view === 'tools') {
			const searchFilter = root.querySelector('[data-filter="search"]');
			const toolsetFilter = root.querySelector('[data-filter="toolset"]');
			const tierFilter = root.querySelector('[data-filter="tier"]');
			const clearButton = root.querySelector('[data-clear]');
			const countEl = root.querySelector('[data-tools-count]');
			const noResultsEl = root.querySelector('[data-tools-no-results]');
			const list = root.querySelector('[data-tools]');
			/**
			 * Plain risk-tier explanations shown next to every tool.
			 *
			 * The catalog never grants execution: these lines describe the
			 * tier so an administrator can judge a tool before scoping a key.
			 */
			const tierHelp = {
				read: __('Read: view permitted data', 'xnetvn-agent-hub-wp'),
				write: __('Write: create or change content', 'xnetvn-agent-hub-wp'),
				destructive: __('Destructive: permanently delete permitted data', 'xnetvn-agent-hub-wp'),
				admin: __('Admin: install software or manage site operations', 'xnetvn-agent-hub-wp'),
			};
			let catalog = [];
			/**
			 * Check one catalog entry against a trimmed lowercase query.
			 *
			 * The query is a plain substring over the stable text fields, never
			 * a regular expression, so catalog text cannot become code.
			 *
			 * @param {Object} item Catalog entry.
			 * @param {string} query Trimmed lowercase search text.
			 * @return {boolean} Whether the entry matches.
			 */
			const matchesQuery = (item, query) => {
				if (query === '') return true;
				return [item.name, item.tool_name, item.label, item.description]
					.some(field => typeof field === 'string' && field.toLowerCase().includes(query));
			};
			/**
			 * Render the filtered catalog, sorted by name, as text only.
			 *
			 * Every value travels through textContent: markup-like catalog text
			 * stays readable text and never becomes an element.
			 */
			const renderTools = () => {
				if (!list) return;
				const query = searchFilter ? searchFilter.value.trim().toLowerCase() : '';
				const toolset = toolsetFilter ? toolsetFilter.value : 'all';
				const tier = tierFilter ? tierFilter.value : 'all';
				const shown = catalog
					.filter(item => (toolset === 'all' || item.toolset === toolset)
						&& (tier === 'all' || item.tier === tier)
						&& matchesQuery(item, query))
					.sort((left, right) => String(left.name).localeCompare(String(right.name)));
				clear(list);
				shown.forEach(item => {
					const article = node('article');
					article.appendChild(node('h2', item.label));
					article.appendChild(node('code', item.name));
					article.appendChild(node('p', item.description));
					const tierText = Object.prototype.hasOwnProperty.call(tierHelp, item.tier) ? tierHelp[item.tier] : item.tier;
					// translators: 1: risk tier explanation, 2: toolset name.
					article.appendChild(node('p', wp.i18n.sprintf(__('Risk tier: %1$s. Toolset: %2$s.', 'xnetvn-agent-hub-wp'), tierText, item.toolset)));
					const details = document.createElement('details');
					details.appendChild(node('summary', __('Technical details', 'xnetvn-agent-hub-wp')));
					// translators: %s: stable tool name.
					details.appendChild(node('p', wp.i18n.sprintf(__('Tool name: %s', 'xnetvn-agent-hub-wp'), item.tool_name)));
					// translators: %s: required WordPress capability.
					details.appendChild(node('p', wp.i18n.sprintf(__('Required capability: %s', 'xnetvn-agent-hub-wp'), item.visibility_cap)));
					article.appendChild(details);
					list.appendChild(article);
				});
				toggleEmpty(catalog.length === 0);
				if (noResultsEl) noResultsEl.hidden = !(catalog.length > 0 && shown.length === 0);
				if (countEl) {
					// translators: 1: shown tools, 2: total tools.
					countEl.textContent = wp.i18n.sprintf(__('%1$d of %2$d tools', 'xnetvn-agent-hub-wp'), shown.length, catalog.length);
				}
			};
			/**
			 * Restore every lookup control to its default and show the catalog.
			 */
			const clearFilters = () => {
				if (searchFilter) searchFilter.value = '';
				if (toolsetFilter) toolsetFilter.value = 'all';
				if (tierFilter) tierFilter.value = 'all';
				renderTools();
			};
			if (searchFilter) searchFilter.addEventListener('input', renderTools);
			if (toolsetFilter) toolsetFilter.addEventListener('change', renderTools);
			if (tierFilter) tierFilter.addEventListener('change', renderTools);
			if (clearButton) clearButton.addEventListener('click', clearFilters);
			const load = () => {
				const id = beginRead();
				return api('/tools').then(items => {
					if (!isCurrent(id)) return;
					catalog = Array.isArray(items) ? items : [];
					if (toolsetFilter) {
						const current = toolsetFilter.value;
						while (toolsetFilter.options.length > 1) toolsetFilter.remove(1);
						Array.from(new Set(catalog.map(item => item.toolset).filter(set => typeof set === 'string' && set !== ''))).sort().forEach(set => {
							const option = document.createElement('option');
							option.value = set;
							option.textContent = set;
							toolsetFilter.appendChild(option);
						});
						toolsetFilter.value = Array.from(toolsetFilter.options).some(option => option.value === current) ? current : 'all';
					}
					renderTools();
					settleRead(id);
				}).catch(error => {
					settleRead(id, error);
				});
			};
			setupRetry(load);
			load();
		}
	});
}());
