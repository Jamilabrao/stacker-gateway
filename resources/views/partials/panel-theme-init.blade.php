@php
    $scheme = config('getfy.panel_color_scheme', \App\Support\PanelColorScheme::defaults());
@endphp
<script>
(function(){try{
    var policy=@json($scheme);
    var isDark=false;
    var stored=null;
    if(!policy.locked){stored=localStorage.getItem('theme');}
    if(policy.locked){
        if(policy.mode==='system'){isDark=window.matchMedia('(prefers-color-scheme: dark)').matches;}
        else{isDark=policy.mode==='dark';}
    }else if(stored==='light'||stored==='dark'){isDark=stored==='dark';}
    else if(policy.mode==='system'){isDark=window.matchMedia('(prefers-color-scheme: dark)').matches;}
    else{isDark=policy.mode==='dark';}
    document.documentElement.classList.toggle('dark',isDark);
}catch(_){}})();
</script>
