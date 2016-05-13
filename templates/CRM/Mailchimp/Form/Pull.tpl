<div class="crm-block crm-form-block crm-campaignmonitor-sync-form-block">

  {if $smarty.get.state eq 'done'}
    <div class="help">
      {ts}Import completed with result counts as:{/ts}<br/>
      {foreach from=$stats item=group}
      <h2>{$group.name}</h2>
      <table class="form-layout-compressed">
      <tr><td>{ts}Contacts on CiviCRM and in this group (originally){/ts}:</td><td>{$group.stats.c_count}</td></tr>
      <tr><td>{ts}Contacts on Mailchimp{/ts}:</td><td>{$group.stats.mc_count}</td></tr>
      <tr><td>{ts}Contacts that were in sync already{/ts}:</td><td>{$group.stats.in_sync}</td></tr>
      <tr><td>{ts}Contacts updated in CiviCRM{/ts}:</td><td>{$group.stats.updates}</td></tr>
      <tr><td>{ts}New Contacts created and added to membership group{/ts}:</td><td>{$group.stats.add_new}</td></tr>
      <tr><td>{ts}Existing Contacts added to membership group{/ts}:</td><td>{$group.stats.add_existing}</td></tr>
      <tr><td>{ts}Contacts removed from membership group{/ts}:</td><td>{$group.stats.unsubscribes}</td></tr>
      </table>
      {/foreach}
    </div>
  {else}
    {$summary}
    <div class="crm-submit-buttons">
      {include file="CRM/common/formButtons.tpl"}
    </div>
  {/if}
</div>
