/* global wcsi_data, jQuery, document */
jQuery(document).ready(function ($) {
	var counter = 0,
		import_count = 0,
		warning_count = 0,
		error_count = 0,
		$timeout = $('#wcsi-timeout'),
		$time_completition = $('#wcsi-time-completion'),
		$completed_message = $('#wcsi-completed-message'),
		$importer_loading = $('.importer-loading'),
		$subsubsub = $('.subsubsub'),
		$progress = $('#wcsi-progress'),
		$all_tbody = $('#wcsi-all-tbody'),
		$failed_tbody = $('#wcsi-failed-tbody'),
		$warning_tbody = $('#wcsi-warning-tbody'),
		$test_passed = $('#wcsi-test-passed'),
		$test_failed = $('#wcsi-test-failed'),
		$error_count = $('#wcsi-error-count'),
		$warning_count = $('#wcsi-warning-count'),
		$all_count = $('#wcsi-all-count'),
		$failed_count = $('#wcsi-failed-count'),
		$error_title = $('#wcsi-error-title'),
		$warning_title = $('#wcsi-warning-title'),
		$test_errors = $('#test_errors'),
		$test_warnings = $('#test_warnings'),
		$completed_percent = $('#wcsi-completed-percent'),
		ajax_import = function (start_pos, end_pos, row_start) {
			var data = {
				action:				'wilderness_import_request',
				file_id:			wcsi_data.file_id,
				start:				start_pos,
				end:				end_pos,
				row_num:			row_start,
				test_mode:			wcsi_data.test_mode,
				email_customer:		wcsi_data.email_customer,
				add_memberships:	wcsi_data.add_memberships,
				wcsie_wpnonce:		wcsi_data.import_wpnonce
			};

			$.ajax({
				url: wcsi_data.ajax_url,
				type: 'POST',
				wcsi_data: wcsi_data,
				timeout: 360000,
				success: function (results) {
					var i,
						x,
						c,
						warnings = [],
						success  = 0,
						failed   = 0,
						critical = 0,
						minor    = 0,
						errors   = [],
						table_data,
						row_classes,
						warning_alternate,
						warning_string,
						error_string = '',
						append_text = '',
						append_warning_text = '',
						append_failed_text = '';

                    for (i = 0; i < results.length; i += 1) {
                        table_data  = '';
                        row_classes = (i % 2) ? '' : 'alternate';

                        if (results[i].status === 'success') {
                            warnings = results[i].warning;
                            append_text += '<tr class="' + row_classes + '">';

                            table_data += '<td class="row ' + ((warnings.length > 0) ? 'warning' : 'success') + '">' + wcsi_data.success + '</td>';
                            table_data += '<td class="row">' + (results[i].subscription !== null  ? results[i].subscription : '-') + '</td>';
                            table_data += '<td class="row">' + results[i].items + '</td>';
                            table_data += '<td class="row">' + results[i].username + '</td>';
                            table_data += '<td class="row column-status"><mark class="' + results[i].subscription_status + '">' + results[i].subscription_status + '</mark></td>';
                            table_data += '<td class="row">' + warnings.length + '</td>';

                            append_text += table_data;
                            append_text += '</tr>';

                            if (warnings.length > 0) {
                                warning_alternate = (warning_count % 2) ? '' : 'alternate';
                                warning_string = '<td class="warning" colspan="6">' + ((warnings.length > 1) ? wcsi_data.warnings : wcsi_data.warning) + ':';

                                for (x = 0; x < warnings.length; x += 1) {
                                    warning_string += '<br>' + (x + 1) + '. ' + warnings[x];
                                }
                                warning_string += '</td>';

                                append_text += '<tr class="' + row_classes + '">' + warning_string + '</tr>';
                                append_warning_text += '<tr class="' + warning_alternate + '">' + table_data + '</tr><tr class="' + warning_alternate + '">' + warning_string + '</tr>';

                                warning_count += 1;
                            }
                        } else {
                            table_data += '<td class="row error-import">' + wcsi_data.failed + '</td>';
                            for (x = 0; x < results[i].error.length; x += 1) {
                                error_string += '<br>' + (x + 1) + '. ' + results[i].error[x];
                            }

                            table_data += '<td colspan="5">' + wcsi_data.error_string + '</td>';
                            table_data = table_data.replace('{row_number}', results[i].row_number);
                            table_data = table_data.replace('{error_messages}', error_string);

                            append_text += '<tr class="' + row_classes + ' error-import">' + table_data + '</tr>';
                            append_failed_text += '<tr class="' + row_classes + ' error-import">' + table_data + '</tr>';

                            error_count += 1;
                        }
                    }

                    // Add all the strings to the dom once instead of on every iteration
                    $all_tbody.append(append_text);
                    $warning_tbody.append(append_warning_text);

                    if (append_failed_text.length) {
                        $failed_tbody.append(append_failed_text);
                    }

                    import_count += results.length;

                    $warning_count.html('(' + warning_count + ')');
                    $failed_count.html('(' + error_count + ')');
                    $all_count.html('(' + import_count + ')');

					counter += 2;
					if ((counter / 2) >= wcsi_data.total) {
                        $importer_loading.addClass('finished').removeClass('importer-loading');
                        $importer_loading.html('<td colspan="6" class="row">' + wcsi_data.finished_importing + '</td>');
						$completed_message.show();
						$completed_percent.html('100%');
					} else {
						// calculate percentage completed
						$completed_percent.html(((((counter / 2) * wcsi_data.rows_per_request) / (wcsi_data.total * wcsi_data.rows_per_request)) * 100).toFixed(0) + '%');
						ajax_import(wcsi_data.file_positions[counter], wcsi_data.file_positions[counter + 1], wcsi_data.start_row_num[counter / 2]);
					}
				},
				error: function (xmlhttprequest, textstatus) {
					$importer_loading.addClass('finished').removeClass('importer-loading');
					if (textstatus === 'timeout') {
						$timeout.show();
						$completed_message.html($timeout.html());
						$completed_message.show();
						$time_completition.hide();
					}
				}

			});
		};

	ajax_import(wcsi_data.file_positions[counter], wcsi_data.file_positions[counter + 1], wcsi_data.start_row_num[counter / 2]);

	$subsubsub.on('click', 'a', function (e) {
		e.preventDefault();
		var id = $(this).parent('li').attr('wcsi_data-value');

		$progress.find('tbody').hide();
		$progress.find('#wcsi-' + id + '-tbody').show();
	});
});
