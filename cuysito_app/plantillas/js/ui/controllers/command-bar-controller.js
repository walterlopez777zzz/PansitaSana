export function createCommandBarController({store,barEl,barInputEl,backdropEl,sidebarSearchEl}={}){
 let isOpen=!1;
 let searchTimer=0;
 const scrollPickerHeaderIntoView=()=>{const header=document.querySelector(".picker-section__header")||document.querySelector(".grid-section");if(!header)return;const top=header.getBoundingClientRect().top+window.scrollY,offsetTop=Math.max(0,top-25);window.scrollY<offsetTop&&window.scrollTo({top:offsetTop,behavior:"auto"})};
 const queueSearchUpdate=value=>{searchTimer&&clearTimeout(searchTimer),searchTimer=setTimeout(()=>{searchTimer=0,store?.update?.("ui.searchTerm",value)},100)};
 const syncBarInput=value=>{barInputEl&&barInputEl.value!==value&&(barInputEl.value=value)};
 const syncSidebarInput=value=>{sidebarSearchEl&&sidebarSearchEl.value!==value&&(sidebarSearchEl.value=value)};
 const handleSearchValue=value=>{syncBarInput(value),syncSidebarInput(value),queueSearchUpdate(value)};
 const show=()=>{if(isOpen||!barEl||!barInputEl||!backdropEl)return;scrollPickerHeaderIntoView(),isOpen=!0,barEl.setAttribute("aria-hidden","false"),backdropEl.classList.add("command-bar__backdrop--active");const value=store?.getState?.()?.ui?.searchTerm??"";barInputEl.value=value,requestAnimationFrame(()=>{barInputEl.focus(),barInputEl.select()})};
 const hide=()=>{isOpen&&(isOpen=!1,barEl?.setAttribute("aria-hidden","true"),backdropEl?.classList.remove("command-bar__backdrop--active"),barInputEl?.blur())};
 const handleBarInput=()=>{handleSearchValue(barInputEl?.value??"")};
 const handleSidebarInput=()=>{handleSearchValue(sidebarSearchEl?.value??"")};
 const handleBarKeydown=event=>{if("Escape"===event.key)return event.preventDefault(),event.stopPropagation(),void hide();"F3"===event.key&&(event.preventDefault(),event.stopPropagation(),hide())};
 const handleBackdropPointerDown=event=>{event.preventDefault(),hide()};
 return barInputEl&&(barInputEl.addEventListener("input",handleBarInput),barInputEl.addEventListener("keydown",handleBarKeydown)),backdropEl&&backdropEl.addEventListener("pointerdown",handleBackdropPointerDown),sidebarSearchEl&&sidebarSearchEl.addEventListener("input",handleSidebarInput),Object.freeze({toggle:()=>{isOpen?hide():show()},isOpen:()=>isOpen,show,hide,destroy:()=>{hide(),searchTimer&&clearTimeout(searchTimer),searchTimer=0,barInputEl&&(barInputEl.removeEventListener("input",handleBarInput),barInputEl.removeEventListener("keydown",handleBarKeydown)),backdropEl&&backdropEl.removeEventListener("pointerdown",handleBackdropPointerDown),sidebarSearchEl&&sidebarSearchEl.removeEventListener("input",handleSidebarInput)}})
}