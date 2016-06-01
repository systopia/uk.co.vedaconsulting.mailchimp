<div class="crm-block crm-form-block crm-campaignmonitor-sync-form-block">

  {if $smarty.get.state eq 'done'}
    <div class="help">
      {if $dry_run}
        {ts}<strong>Dry Run: no contacts/members actually changed.</strong>{/ts}
      {/if}
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
    {if $error_messages}
    <h2>Error messages</h2>
    <p>These errors have come from the last sync operation (whether that was a 'pull' or a 'push').</p>
    <table>
    <thead><tr><th>Group Id</th><th>Name and Email</th><th>Error</th></tr>
    </thead>
    <tbody>
    {foreach from=$error_messages item=msg}
      <tr><td>{$msg.group}</td>
      <td>{$msg.name} {$msg.email}</td>
      <td>{$msg.message}</td>
    </tr>
    {/foreach}
    </table>
    {/if}
  {else}
    {$summary}
    {$form.mc_dry_run.html} {$form.mc_dry_run.label}
    <div class="crm-submit-buttons">
      {include file="CRM/common/formButtons.tpl"}
    </div>
  {/if}
</div>
