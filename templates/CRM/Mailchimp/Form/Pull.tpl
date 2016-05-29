<div class="crm-block crm-form-block crm-campaignmonitor-sync-form-block">

  {if $smarty.get.state eq 'done'}
    <div class="help">
      {ts}Import completed with result counts as:{/ts}<br/>
      {foreach from=$stats item=group}
      <h2>{$group.name}</h2>
      <table class="form-layout-compressed">
      <tr><td>{ts}Contacts on CiviCRM and in membership group (originally){/ts}:</td><td>{$group.stats.c_count}</td></tr>
      <tr><td>&nbsp;&nbsp;&nbsp;{ts}Of these, kept because subscribed at Mailchimp:{/ts}:</td><td>{$group.stats.in_sync}</td></tr>
      <tr><td>&nbsp;&nbsp;&nbsp;{ts}Of these, removed because not subscribed at Mailchimp:{/ts}:</td><td>{$group.stats.removed}</td></tr>
      <tr><td>{ts}Contacts on Mailchimp{/ts}:</td><td>{$group.stats.mc_count}</td></tr>
      <tr><td>&nbsp;&nbsp;&nbsp;{ts}Of these, already in membership group{/ts}:</td><td>{$group.stats.in_sync}</td></tr>
      <tr><td>&nbsp;&nbsp;&nbsp;{ts}Of these, existing contacts added to membership group{/ts}:</td><td>{$group.stats.joined}</td></tr>
      <tr><td>&nbsp;&nbsp;&nbsp;{ts}Of these, new contacts created{/ts}:</td><td>{$group.stats.created}</td></tr>
      <tr><td>{ts}Existing contacts updated{/ts}:</td><td>{$group.stats.updated}</td></tr>
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
