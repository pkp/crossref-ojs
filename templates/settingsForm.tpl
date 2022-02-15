{**
 * plugins/generic/crossref/templates/settingsForm.tpl
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under The MIT License. For full terms see the file LICENSE.
 *
 * Crossref plugin settings
 *
 *}
<script type="text/javascript">
	$(function() {ldelim}
		// Attach the form handler.
		$('#crossrefSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<div class="pkp_notification" id="crossrefConfigurationErrors">
	{foreach from=$configurationErrors item=configurationError}
		{if $configurationError == $smarty.const.DOI_EXPORT_CONFIG_ERROR_DOIPREFIX}
			{include file="controllers/notification/inPlaceNotificationContent.tpl" notificationId=crossrefConfigurationErrors notificationStyleClass="notifyWarning" notificationTitle="plugins.importexport.common.missingRequirements"|translate notificationContents="plugins.importexport.common.error.DOIsNotAvailable"|translate}
		{elseif $configurationError == $smarty.const.EXPORT_CONFIG_ERROR_SETTINGS}
			{include file="controllers/notification/inPlaceNotificationContent.tpl" notificationId=crossrefConfigurationErrors notificationStyleClass="notifyWarning" notificationTitle="plugins.importexport.common.missingRequirements"|translate notificationContents="plugins.importexport.common.error.pluginNotConfigured"|translate}
		{/if}
	{/foreach}
	{if !$currentContext->getData('publisherInstitution')}
		{capture assign=journalSettingsUrl}{url router=\PKP\core\PKPApplication::ROUTE_PAGE page="management" op="settings" path="context" escape=false}{/capture}
		{capture assign=missingPublisherMessage}{translate key="plugins.importexport.crossref.error.publisherNotConfigured" journalSettingsUrl=$journalSettingsUrl}{/capture}
		{include file="controllers/notification/inPlaceNotificationContent.tpl" notificationId=crossrefConfigurationErrors notificationStyleClass="notifyWarning" notificationTitle="plugins.importexport.common.missingRequirements"|translate notificationContents=$missingPublisherMessage}
	{/if}
	{if !$currentContext->getData('onlineIssn') && !$currentContext->getData('printIssn')}
		{capture assign=journalSettingsUrl}{url router=\PKP\core\PKPApplication::ROUTE_PAGE page="management" op="settings" path="context" escape=false}{/capture}
		{capture assign=missingIssnMessage}{translate key="plugins.importexport.crossref.error.issnNotConfigured" journalSettingsUrl=$journalSettingsUrl}{/capture}
		{include file="controllers/notification/inPlaceNotificationContent.tpl" notificationId=crossrefConfigurationErrors notificationStyleClass="notifyWarning" notificationTitle="plugins.importexport.common.missingRequirements"|translate notificationContents=$missingIssnMessage}
	{/if}
</div>

<form class="pkp_form" id="crossrefSettingsForm" method="post" action="{url router=\PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" plugin="CrossRefExportPlugin" category="importexport" verb="save"}">
	{csrf}
	{fbvFormArea id="crossrefSettingsFormArea"}
		<p class="pkp_help">{translate key="plugins.importexport.crossref.settings.depositorIntro"}</p>
		{fbvFormSection}
			{fbvElement type="text" id="depositorName" value=$depositorName required="true" label="plugins.importexport.crossref.settings.form.depositorName" maxlength="60" size=$fbvStyles.size.MEDIUM}
			{fbvElement type="text" id="depositorEmail" value=$depositorEmail required="true" label="plugins.importexport.crossref.settings.form.depositorEmail" maxlength="90" size=$fbvStyles.size.MEDIUM}
		{/fbvFormSection}
		{fbvFormSection}
			<p class="pkp_help">{translate key="plugins.importexport.crossref.registrationIntro"}</p>
			{fbvElement type="text" id="username" value=$username label="plugins.importexport.crossref.settings.form.username" maxlength="50" size=$fbvStyles.size.MEDIUM}
			{fbvElement type="text" password="true" id="password" value=$password label="plugins.importexport.common.settings.form.password" maxLength="50" size=$fbvStyles.size.MEDIUM}
			<span class="instruct">{translate key="plugins.importexport.common.settings.form.password.description"}</span><br/>
		{/fbvFormSection}
		{fbvFormSection list="true"}
			{fbvElement type="checkbox" id="automaticRegistration" label="plugins.importexport.crossref.settings.form.automaticRegistration.description" checked=$automaticRegistration|compare:true}
		{/fbvFormSection}
		{fbvFormSection list="true"}
			{fbvElement type="checkbox" id="testMode" label="plugins.importexport.crossref.settings.form.testMode.description" checked=$testMode|compare:true}
		{/fbvFormSection}
	{/fbvFormArea}
	{fbvFormButtons submitText="common.save"}
	<p><span class="formRequired">{translate key="common.requiredField"}</span></p>
</form>
