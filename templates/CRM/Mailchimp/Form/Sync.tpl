<div class="crm-block crm-form-block crm-campaignmonitor-sync-form-block">
  {if $smarty.get.state eq 'done'}
    <div class="help">
      {if $dry_run}
        {ts}<strong>Dry Run: no contacts/members actually changed.</strong>{/ts}
      {/if}
      {ts}Sync completed with result counts as:{/ts}<br/> 
      {foreach from=$stats item=group}
      {assign var="groups" value=$group.stats.group_id|@implode:','}
      <h2>{$group.name}</h2>
      <table class="form-layout-compressed">
      <tr><td>{ts}Contacts on CiviCRM{/ts}:</td><td>{$group.stats.c_count}</td></tr>
      <tr><td>{ts}Contacts on Mailchimp (originally){/ts}:</td><td>{$group.stats.mc_count}</td></tr>
      <tr><td>{ts}Contacts that were in sync already{/ts}:</td><td>{$group.stats.in_sync}</td></tr>
      <tr><td>{ts}Contacts updated at Mailchimp{/ts}:</td><td>{$group.stats.updates}</td></tr>
      <tr><td>{ts}Contacts Subscribed{/ts}:</td><td>{$group.stats.additions}</td></tr>
      <tr><td>{ts}Contacts Unsubscribed from Mailchimp{/ts}:</td><td>{$group.stats.unsubscribes}</td></tr>
      </table>
      {/foreach}
    </div>
  {else}
    {$summary}
    {$form.mc_dry_run.html} {$form.mc_dry_run.label}
    <div class="crm-submit-buttons">
      {include file="CRM/common/formButtons.tpl"}
    </div>
  {/if}
</div>
