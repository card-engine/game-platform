<!-- 混合菜单 -->
<template>
  <div class="relative box-border flex-c w-full min-w-0 overflow-hidden">
    <!-- 左侧滚动按钮 -->
    <div v-show="showLeftArrow" class="button-arrow left-0" @click="scroll('left')">
      <ElIcon>
        <ArrowLeft />
      </ElIcon>
    </div>

    <!-- 滚动容器 -->
    <ElScrollbar
      ref="scrollbarRef"
      class="min-w-0 flex-1"
      wrap-class="scrollbar-wrapper"
      :horizontal="true"
      @scroll="handleScroll"
      @wheel="handleWheel"
    >
      <div class="box-border flex-c flex-shrink-0 flex-nowrap h-15 whitespace-nowrap">
        <template v-for="item in processedMenuList" :key="item.meta.title">
          <div
            v-if="!item.meta.isHide"
            class="menu-item relative flex-shrink-0 h-10 px-3 text-sm flex-c c-p hover:text-theme"
            :class="{
              'menu-item-active text-theme': item.isActive
            }"
            @click="handleMenuClick(item, $event)"
          >
            <ArtSvgIcon
              :icon="item.meta.icon"
              class="text-lg text-g-700 dark:text-g-800 mr-1"
              :class="item.isActive && '!text-theme'"
            />
            <span
              class="text-md text-g-700 dark:text-g-800"
              :class="item.isActive && '!text-theme'"
            >
              {{ item.formattedTitle }}
            </span>
            <div v-if="item.meta.showTextBadge" class="art-text-badge">
              {{ item.meta.showTextBadge }}
            </div>
            <div v-if="item.meta.showBadge" class="art-badge art-badge-mixed" />
          </div>
        </template>
      </div>
    </ElScrollbar>

    <!-- 右侧滚动按钮 -->
    <div v-show="showRightArrow" class="button-arrow right-0" @click="scroll('right')">
      <ElIcon>
        <ArrowRight />
      </ElIcon>
    </div>
  </div>
</template>

<script setup lang="ts">
  import { ref, computed, onMounted, nextTick, watch } from 'vue'
  import { ArrowLeft, ArrowRight } from '@element-plus/icons-vue'
  import { useResizeObserver, useThrottleFn } from '@vueuse/core'
  import type { ScrollbarInstance } from 'element-plus'
  import { formatMenuTitle } from '@/utils/router'
  import { handleMenuJump } from '@/utils/navigation'
  import type { AppRouteRecord } from '@/types/router'

  defineOptions({ name: 'ArtMixedMenu' })

  interface Props {
    /** 菜单列表数据 */
    list: AppRouteRecord[]
  }

  interface ProcessedMenuItem extends AppRouteRecord {
    isActive: boolean
    formattedTitle: string
  }

  type ScrollDirection = 'left' | 'right'

  const route = useRoute()

  const props = withDefaults(defineProps<Props>(), {
    list: () => []
  })

  const scrollbarRef = ref<ScrollbarInstance>()
  const showLeftArrow = ref(false)
  const showRightArrow = ref(false)

  /**
   * 获取当前激活路径
   * 使用computed缓存，避免重复计算
   */
  const currentActivePath = computed(() => {
    return String(route.meta.activePath || route.path)
  })

  /**
   * 判断菜单项是否为激活状态
   * 递归检查子菜单中是否包含当前路径
   * @param item 菜单项数据
   * @returns 是否为激活状态
   */
  const isMenuItemActive = (item: AppRouteRecord): boolean => {
    const activePath = currentActivePath.value

    // 如果有子菜单，递归检查子菜单
    if (item.children?.length) {
      return item.children.some((child) => {
        if (child.children?.length) {
          return isMenuItemActive(child)
        }
        return child.path === activePath
      })
    }

    // 直接比较路径
    return item.path === activePath
  }

  /**
   * 预处理菜单列表
   * 缓存每个菜单项的激活状态和格式化标题
   */
  const processedMenuList = computed<ProcessedMenuItem[]>(() => {
    return props.list.map((item) => ({
      ...item,
      isActive: isMenuItemActive(item),
      formattedTitle: formatMenuTitle(item.meta.title)
    }))
  })

  /**
   * 处理滚动事件的核心逻辑
   * 根据滚动位置显示/隐藏滚动按钮
   */
  const handleScrollCore = (): void => {
    if (!scrollbarRef.value?.wrapRef) return

    const { scrollLeft, scrollWidth, clientWidth } = scrollbarRef.value.wrapRef

    // 判断是否显示左侧滚动按钮
    showLeftArrow.value = scrollLeft > 1

    // 判断是否显示右侧滚动按钮
    showRightArrow.value = scrollLeft + clientWidth < scrollWidth - 1
  }

  /**
   * 节流后的滚动事件处理函数
   * 调整节流间隔为16ms，约等于60fps
   */
  const handleScroll = useThrottleFn(handleScrollCore, 16)

  /**
   * 滚动菜单容器
   * @param direction 滚动方向，left 或 right
   */
  const scroll = (direction: ScrollDirection): void => {
    if (!scrollbarRef.value?.wrapRef) return

    const wrap = scrollbarRef.value.wrapRef
    const distance = Math.min(200, wrap.clientWidth * 0.8)

    // 平滑滚动到目标位置
    wrap.scrollTo({
      left: wrap.scrollLeft + (direction === 'left' ? -distance : distance),
      behavior: 'smooth'
    })
  }

  // 只移动到菜单可见，不居中或带出相邻菜单。
  const revealMenu = (element: HTMLElement): void => {
    const wrap = scrollbarRef.value?.wrapRef
    if (!wrap) return
    const viewport = wrap.getBoundingClientRect()
    const rect = element.getBoundingClientRect()
    const delta =
      rect.left < viewport.left
        ? rect.left - viewport.left
        : Math.max(0, rect.right - viewport.right)
    if (delta) {
      wrap.scrollTo({ left: wrap.scrollLeft + delta, behavior: 'instant' })
    }
  }

  const handleMenuClick = (item: AppRouteRecord, event: MouseEvent): void => {
    const element = event.currentTarget as HTMLElement
    revealMenu(element)

    const wrap = scrollbarRef.value?.wrapRef
    if (wrap) {
      const viewport = wrap.getBoundingClientRect()
      const rect = element.getBoundingClientRect()
      const edge = 12
      const sibling =
        rect.right >= viewport.right - edge
          ? element.nextElementSibling
          : rect.left <= viewport.left + edge
            ? element.previousElementSibling
            : null

      if (sibling instanceof HTMLElement) {
        const siblingRect = sibling.getBoundingClientRect()
        const delta =
          sibling === element.nextElementSibling
            ? siblingRect.right - viewport.right + edge
            : siblingRect.left - viewport.left - edge
        const max = wrap.scrollWidth - wrap.clientWidth
        wrap.scrollTo({
          left: Math.max(0, Math.min(max, wrap.scrollLeft + delta)),
          behavior: 'instant'
        })
      }
    }
    handleMenuJump(item, true)
  }

  /**
   * 处理鼠标滚轮事件
   * 优化滚轮响应性能
   * @param event 滚轮事件
   */
  const handleWheel = (event: WheelEvent): void => {
    const wrap = scrollbarRef.value?.wrapRef
    if (event.ctrlKey || !wrap || wrap.scrollWidth <= wrap.clientWidth) return

    event.preventDefault()
    event.stopPropagation()
    // 触控板保留真实位移和惯性；鼠标纵向滚轮转换为横向，兼容行/页单位。
    const delta = Math.abs(event.deltaX) > Math.abs(event.deltaY) ? event.deltaX : event.deltaY
    const unit = event.deltaMode === 1 ? 16 : event.deltaMode === 2 ? wrap.clientWidth : 1
    wrap.scrollTo({ left: wrap.scrollLeft + delta * unit, behavior: 'instant' })
  }

  /**
   * 初始化滚动状态
   */
  const initScrollState = (): void => {
    nextTick(() => {
      const wrap = scrollbarRef.value?.wrapRef
      const active = wrap?.querySelector<HTMLElement>('.menu-item-active')
      if (active) revealMenu(active)
      handleScrollCore()
    })
  }

  // 顶部时间会引起容器尺寸变化，不能因此重置用户正在浏览的滚动位置。
  useResizeObserver(() => scrollbarRef.value?.wrapRef, handleScrollCore)
  watch(
    () => processedMenuList.value.map((item) => `${item.path}:${item.isActive}`).join('|'),
    initScrollState,
    { flush: 'post' }
  )
  watch(() => processedMenuList.value.length, initScrollState)
  onMounted(() => {
    initScrollState()
  })
</script>

<style scoped>
  @reference '@styles/core/tailwind.css';

  .button-arrow {
    @apply absolute 
    top-1/2
    z-2 
    flex
    items-center
    justify-center
    size-7.5
    text-g-600 
    cursor-pointer
    rounded 
    transition-all
    duration-300
    -translate-y-1/2 
    hover:text-g-900 
    hover:bg-g-200;
  }
</style>

<style scoped>
  :deep(.el-scrollbar__bar.is-horizontal) {
    bottom: 5px;
    display: none;
    height: 2px;
  }

  :deep(.scrollbar-wrapper) {
    flex: 1;
    min-width: 0;
    margin: 0 32px;
    overscroll-behavior-x: contain;
  }

  .menu-item-active::after {
    position: absolute;
    right: 0;
    bottom: 0;
    left: 0;
    width: 40px;
    height: 2px;
    margin: auto;
    content: '';
    background-color: var(--theme-color);
  }
</style>
