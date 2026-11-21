<div class="weeklyhours" id="$ID">
    <% loop $Days %>
        <div class="weeklyhours__row" style="display:flex;align-items:center;gap:.75em;margin-bottom:.4em;">
            <label class="weeklyhours__day" for="$InputID" style="flex:0 0 8em;">$Label</label>
            <input type="text" class="text weeklyhours__input form-control" id="$InputID" name="$InputName"
                value="$Value.ATT" placeholder="<%t SilverShop\StoreLocations\Forms\WeeklyHoursField.Placeholder 'closed' %>"
                style="max-width:22em;" autocomplete="off">
        </div>
    <% end_loop %>
</div>
