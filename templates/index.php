<?php
/** @var \OCP\IL10N $l */
/** @var array $_ */
\OCP\Util::addScript('batch', 'main');
\OCP\Util::addStyle('batch', 'main');
?>
<div id="app-content">
	<div id="app-content-batch" class="viewcontainer">

		<!-- Setup: shown until the user has a certificate and a work folder -->
		<div id="batch-setup" class="batch-modal" hidden>
		 <div class="batch-modal-box">
			<div class="batch-modal-head">
				<span><?php p($l->t('Set up Batch')); ?></span>
				<button id="batch-setup-close" class="batch-modal-close" aria-label="Close">✕</button>
			</div>
			<div class="batch-setup-body">
			<div id="batch-setup-nofs" class="batch-note" hidden>
				<?php p($l->t('Batch needs the files_sharding app (it manages your X.509 certificate). Please ask an administrator to enable it.')); ?>
			</div>
			<div id="batch-setup-steps" hidden>
				<div class="batch-setup-step">
					<span class="batch-setup-label"><?php p($l->t('Certificate')); ?>:</span>
					<span id="batch-cert-status"></span>
					<button id="batch-gen-cert" class="button" title="<?php p($l->t('Generating a new certificate invalidates previously generated certificates for data access.')); ?>"><?php p($l->t('Generate certificate')); ?></button>
				</div>
				<div class="batch-setup-step">
					<label class="batch-setup-label" for="batch-workfolder"><?php p($l->t('Work folder')); ?>:</label>
					<input type="text" id="batch-workfolder" placeholder="/Batch" />
					<button id="batch-browse-folder" class="button"><?php p($l->t('Browse')); ?></button>
					<button id="batch-save-settings" class="button"><?php p($l->t('Save')); ?></button>
				</div>
				<div class="batch-setup-step">
					<span class="batch-setup-label"><?php p($l->t('Templates')); ?>:</span>
					<button id="batch-get-templates" class="button" title="<?php p($l->t('Get a default set of job templates to get started. Existing templates with the same names will be overwritten.')); ?>"><?php p($l->t('Copy job templates into my work folder')); ?></button>
				</div>
			</div>
			</div>
		 </div>
		</div>

		<!-- Main: job editor + job list -->
		<div id="batch-main" hidden>
			<div id="batch-controls">
				<button id="batch-new" class="primary"><?php p($l->t('New job')); ?></button>
				<button id="batch-refresh" class="button"><?php p($l->t('Refresh')); ?></button>
				<button id="batch-delete" class="button" disabled><?php p($l->t('Delete selected')); ?></button>
				<input type="text" id="batch-filter" class="batch-filter" placeholder="<?php p($l->t('Filter jobs…')); ?>" />
				<label class="batch-mine-label"><input type="checkbox" id="batch-mine-only" checked /> <?php p($l->t('My jobs only')); ?></label>
				<span id="batch-loading" hidden><span class="icon-loading-small"></span> <?php p($l->t('Working…')); ?></span>
				<a id="batch-setup-link" href="#" class="batch-link"><?php p($l->t('Setup')); ?></a>
			</div>

			<div id="batch-editor" class="batch-panel" hidden>
				<div class="batch-editor-row">
					<label for="batch-script-select"><?php p($l->t('Template / script')); ?>:</label>
					<select id="batch-script-select"><option value=""><?php p($l->t('— choose —')); ?></option></select>
					<label for="batch-input-file"><?php p($l->t('Input file')); ?>:</label>
					<input type="text" id="batch-input-file" placeholder="<?php p($l->t('optional path in your files, e.g. /data/in.mp4')); ?>" />
				</div>
				<textarea id="batch-script-text" spellcheck="false" placeholder="#!/bin/bash&#10;#GRIDFACTORY -n my_job&#10;echo hello"></textarea>
				<div class="batch-editor-actions">
					<button id="batch-submit" class="primary"><?php p($l->t('Submit')); ?></button>
					<button id="batch-save-script" class="button"><?php p($l->t('Save script')); ?></button>
					<button id="batch-cancel" class="button"><?php p($l->t('Cancel')); ?></button>
				</div>
			</div>

			<h2 class="batch-jobs-title"><?php p($l->t('Jobs')); ?></h2>
			<table id="batch-jobs" class="batch-table">
				<thead>
					<tr>
						<th class="batch-col-check"><input type="checkbox" id="batch-select-all" /></th>
						<th class="batch-col-id"><?php p($l->t('ID')); ?></th>
						<th class="batch-col-name"><?php p($l->t('Name')); ?></th>
						<th class="batch-col-status"><?php p($l->t('Status')); ?></th>
						<th class="batch-col-actions"></th>
					</tr>
				</thead>
				<tbody id="batch-jobs-body"></tbody>
			</table>
			<div id="batch-jobs-empty" class="batch-note" hidden><?php p($l->t('No jobs yet.')); ?></div>
		</div>

		<!-- Inspect overlay -->
		<div id="batch-inspect" class="batch-modal" hidden>
			<div class="batch-modal-box">
				<div class="batch-modal-head">
					<span id="batch-inspect-title"></span>
					<button id="batch-inspect-close" class="batch-modal-close" aria-label="Close">✕</button>
				</div>
				<div class="batch-inspect-tabs">
					<button class="batch-tab" data-file="job"><?php p($l->t('Script')); ?></button>
					<button class="batch-tab" data-file="stdout">stdout</button>
					<button class="batch-tab" data-file="stderr">stderr</button>
					<button class="batch-tab" data-info="1"><?php p($l->t('Details')); ?></button>
				</div>
				<pre id="batch-inspect-body"></pre>
			</div>
		</div>

	</div>
</div>
