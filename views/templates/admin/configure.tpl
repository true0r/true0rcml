<div class="container-fluid panel">
    <div class="panel-heading">
        <i class="icon-cogs"></i>
        {$title}
    </div>
    <p>
        <strong>{$link}</strong> - {l s='Используйте эту ссылку для подключения к сайту' mod='true0r1C'}<br/>
        <strong>{$wsKey}</strong> - {l s='Используйте этот ключ как имя (пароль оставьте пустыми)' mod='true0r1C'}
    </p>
    <p>
        <a href="{$linkAction['newWsKey']}" class="btn-link">
            <i class="icon-external-link-sign"></i>
            {l s='Сгенерировать новый токен доступа к веб-сервису (ссылка изменится)' mod='true0r1C'}
        </a>
    </p>
</div>