(() => {
  // The host sidebar owns translation for same-origin pages loaded in its iframe.
  if (window.parent !== window) return;
  const englishToKorean = {
    'hdjv assets inventory': 'HDJV 자산 재고',
    'dashboard': '대시보드', 'home': '홈', 'inventory': '재고', 'settings': '설정',
    'system control panel': '시스템 제어판', 'manage navigation': '메뉴 관리',
    'manage navigation.': '메뉴 관리.', 'recycle bin': '휴지통', 'view database': '데이터베이스 보기',
    'profile & workspaces': '프로필 및 작업 공간', 'profile &amp; workspaces': '프로필 및 작업 공간',
    'profile': '프로필', 'profile & workspaces': '프로필 및 작업 공간', 'profile and workspaces': '프로필 및 작업 공간',
    'profile & workspaces.': '프로필 및 작업 공간.', 'profile &amp; workspaces.': '프로필 및 작업 공간.',
    'database connection problem': '데이터베이스 연결 문제', 'dismiss': '닫기', 'logout': '로그아웃',
    'confirm logout': '로그아웃 확인', 'are you sure you want to log out?': '로그아웃하시겠습니까?',
    'cancel': '취소', 'close': '닫기', 'save': '저장', 'save changes': '변경 사항 저장',
    'save language': '언어 저장', 'language preference': '언어 설정', 'system language': '시스템 언어',
    'choose the language used across the system.': '시스템 전체에서 사용할 언어를 선택하세요.',
    'english': '영어', 'korean': '한국어', '한국어 (korean)': '한국어', 'korean (한국어)': '한국어',
    'your profile': '내 프로필', 'your account details and work information': '계정 및 업무 정보',
    'account management': '계정 관리', 'secure workspace access': '안전한 작업 공간 액세스',
    'manage your personal details, move between team workspaces, and decide who can access your inventory.': '개인 정보를 관리하고 팀 작업 공간을 전환하며 재고에 접근할 사용자를 설정하세요.',
    'department': '부서', 'work area / site': '근무 구역 / 현장', 'keep your account information up to date.': '계정 정보를 최신 상태로 유지하세요.',
    'change password': '비밀번호 변경', 'edit profile': '프로필 수정', 'workspace access': '작업 공간 액세스',
    'manage teammates who can access your workspace': '작업 공간에 접근할 팀원을 관리합니다.',
    'active workspace': '현재 작업 공간', 'switch between your own and shared spaces': '개인 작업 공간과 공유 공간을 전환합니다.',
    'choose a workspace': '작업 공간 선택', 'your selection is saved for your next sign-in.': '선택한 작업 공간은 다음 로그인에도 저장됩니다.',
    'switch workspace': '작업 공간 전환', 'team actions': '팀 작업', 'invite teammates or join an existing workspace': '팀원을 초대하거나 기존 작업 공간에 참여하세요.',
    'invite a teammate': '팀원 초대', 'create a one-time access code': '일회용 액세스 코드를 만듭니다.',
    'join a workspace': '작업 공간 참여', "enter a teammate's invitation code": '팀원의 초대 코드를 입력하세요.',
    'codes are private.': '코드는 비공개입니다.', 'invite codes work once and expire after 7 days. share them directly with the intended teammate.': '초대 코드는 한 번만 사용할 수 있으며 7일 후 만료됩니다. 초대할 팀원에게 직접 공유하세요.',
    'loading profile…': '프로필을 불러오는 중…', 'loading workspace members…': '작업 공간 구성원을 불러오는 중…',
    'no teammates yet': '아직 팀원이 없습니다.', 'generate an invitation code to give a teammate access to this workspace.': '초대 코드를 만들어 팀원에게 작업 공간 액세스 권한을 부여하세요.',
    'teammate': '팀원', 'access level': '액세스 권한', 'joined': '가입일', 'view only': '보기 전용',
    'can edit': '편집 가능', 'remove': '삭제', 'remove access': '액세스 권한 삭제',
    'confirm action': '작업 확인', 'continue': '계속', 'restore item': '항목 복원', 'empty bin': '휴지통 비우기',
    'refresh': '새로고침', 'search': '검색', 'search deleted items': '삭제된 항목 검색', 'empty bin': '휴지통 비우기',
    'empty the recycle bin?': '휴지통을 비우시겠습니까?', 'delete this item forever?': '이 항목을 영구 삭제하시겠습니까?',
    'restore this item?': '이 항목을 복원하시겠습니까?', 'permanent deletion cannot be undone.': '영구 삭제는 취소할 수 없습니다.',
    'loading deleted items': '삭제된 항목을 불러오는 중', 'the recycle bin is empty': '휴지통이 비어 있습니다.',
    'deleted items': '삭제된 항목', 'deleted items will appear here.': '삭제된 항목이 여기에 표시됩니다.',
    'items in bin': '휴지통 항목', 'expiring within 7 days': '7일 이내 만료', 'sql backups available': '사용 가능한 SQL 백업',
    'database': '데이터베이스', 'module database': '모듈 데이터베이스', 'choose a module to inspect its json snapshot. recreating a module replaces that one table in your workspace database.': '모듈을 선택해 JSON 스냅샷을 확인하세요. 모듈을 다시 만들면 작업 공간 데이터베이스의 해당 테이블만 교체됩니다.',
    'module': '모듈', 'recreate module': '모듈 다시 만들기', 'recreate module in database': '데이터베이스에서 모듈 다시 만들기',
    'no module json files are available.': '사용 가능한 모듈 JSON 파일이 없습니다.', 'loading module json…': '모듈 JSON을 불러오는 중…',
    'the current': '현재', 'table': '테이블', 'updated': '업데이트됨',
    'manage navigation': '메뉴 관리', 'active dynamic components matrix': '활성 동적 구성 요소 목록',
    'sidebar button / table identity': '사이드바 버튼 / 테이블 이름', 'target route directory': '대상 경로',
    'status vision': '상태', 'action parameters': '작업', 'visible': '표시', 'hidden': '숨김',
    'configure': '설정', 'add row': '행 추가', 'delete component': '구성 요소 삭제',
    'inject navigation link engine': '메뉴 링크 추가', 'navigation button label': '메뉴 버튼 이름',
    'create inventory table for this node': '이 메뉴에 재고 테이블 만들기', 'custom table name (optional)': '사용자 지정 테이블 이름 (선택 사항)',
    'compile schema & inject node': '스키마 생성 및 메뉴 추가', 'save core configurations': '기본 설정 저장',
    'active column header schemas': '활성 열 머리글 스키마', 'field name': '필드 이름', 'data matrix type': '데이터 유형',
    'append live database column field': '데이터베이스 열 추가', 'append': '추가', 'system deployment & maintenance engine': '시스템 배포 및 유지 관리',
    'restrict client access (maintenance intercept)': '사용자 액세스 제한 (유지 관리)', 'loading…': '불러오는 중…',
    'recycle bin': '휴지통', 'deleted items': '삭제된 항목', 'restore': '복원', 'delete forever': '영구 삭제',
    'database backup & restore': '데이터베이스 백업 및 복원', 'database backup needs attention': '데이터베이스 백업 확인 필요',
    'loading': '불러오는 중', 'no matching items': '일치하는 항목이 없습니다.', 'try another name, table, or item type.': '다른 이름, 테이블 또는 항목 유형을 검색하세요.',
    'profile & workspaces': '프로필 및 작업 공간', 'workspace owner': '작업 공간 소유자', 'owner': '소유자',
    'employee issuance': '직원 자산 지급', 'find or issue assets': '자산 조회 및 지급', 'return assigned equipment or issue inventory to a new employee.': '지급된 장비를 반납하거나 새 직원에게 자산을 지급하세요.',
    'search assets': '자산 검색', 'select': '선택', 'submit': '제출', 'update': '업데이트', 'add': '추가', 'create': '만들기',
    'delete': '삭제', 'edit': '수정', 'download': '다운로드', 'upload': '업로드', 'export': '내보내기', 'import': '가져오기',
    'filter': '필터', 'clear': '지우기', 'back': '뒤로', 'next': '다음', 'previous': '이전', 'yes': '예', 'no': '아니요',
    'loading data…': '데이터를 불러오는 중…', 'no data available': '표시할 데이터가 없습니다.', 'no records found': '레코드를 찾을 수 없습니다.',
    'name': '이름', 'status': '상태', 'date': '날짜', 'created at': '생성일', 'last updated': '최근 업데이트',
    'asset code': '자산 코드', 'serial number': '일련번호', 'model': '모델', 'specs': '사양', 'company': '회사',
    'employee': '직원', 'employee id': '직원 ID', 'job number': '작업 번호', 'department': '부서', 'nationality': '국적',
    'work area': '근무 구역', 'issued date': '지급일', 'remarks': '비고', 'ref no': '참조 번호', 'jobtrade': '직종',
    'dashboard': '대시보드', 'total assets': '전체 자산', 'available': '사용 가능', 'assigned': '지급됨', 'under maintenance': '유지 관리 중',
    'sign in': '로그인', 'username': '사용자 이름', 'password': '비밀번호', 'forgot password': '비밀번호 찾기',
    'create account': '계정 만들기', 'new account': '새 계정', 'first name': '이름', 'last name': '성',
    'remember me': '로그인 유지', 'confirm password': '비밀번호 확인', 'save your permanent account id': '영구 계정 ID 저장',
    'copy account id': '계정 ID 복사', 'i saved my account id': '계정 ID를 저장했습니다',
    'change your temporary password before accessing the workspace.': '작업 공간에 접근하기 전에 임시 비밀번호를 변경하세요.',
    'could not load the database language preference.': '데이터베이스 언어 설정을 불러오지 못했습니다.',
    'language preference saved.': '언어 설정이 저장되었습니다.', 'choose english or korean.': '영어 또는 한국어를 선택하세요.',
    'assets': '자산', 'asset': '자산', 'asset inventory': '자산 재고', 'asset management': '자산 관리',
    'asset assignments': '자산 배정', 'laptop': '노트북', 'laptops': '노트북', 'mouse': '마우스',
    'keyboard': '키보드', 'radio': '무전기', 'monitor': '모니터', 'printer': '프린터',
    'computer': '컴퓨터', 'network': '네트워크', 'software': '소프트웨어', 'hardware': '하드웨어',
    'total': '전체', 'count': '개수', 'records': '레코드', 'items': '항목', 'row': '행', 'rows': '행',
    'add new': '새 항목 추가', 'add new asset': '새 자산 추가', 'manual add': '직접 추가',
    'import excel': 'Excel 가져오기', 'export excel': 'Excel 내보내기', 'select all': '전체 선택',
    'clear filters': '필터 지우기', 'apply filters': '필터 적용', 'this action cannot be undone.': '이 작업은 취소할 수 없습니다.',
    'no items found.': '항목을 찾을 수 없습니다.', 'loading records…': '레코드를 불러오는 중…',
    'loading assets…': '자산을 불러오는 중…', 'failed to load data.': '데이터를 불러오지 못했습니다.',
    'search by name, asset code, or serial number': '이름, 자산 코드 또는 일련번호로 검색',
    'search by name or reference number': '이름 또는 참조 번호로 검색', 'asset status': '자산 상태',
    'assigned to': '지급 대상', 'return date': '반납일', 'issue asset': '자산 지급', 'return asset': '자산 반납',
    'employee name': '직원 이름', 'new sidebar button': '새 사이드바 버튼', 'new field name': '새 필드 이름',
    'visibility': '표시 여부', 'manage your inventory': '재고 관리', 'inventory overview': '재고 개요',
    'recent activity': '최근 활동', 'language': '언어', 'sign out': '로그아웃', 'search navigation…': '메뉴 검색…',
    'location': '위치', 'quantity': '수량', 'supplier': '공급업체', 'category': '범주', 'date added': '등록일',
    'updated at': '수정일', 'in stock': '재고 있음', 'out of stock': '재고 없음', 'returned': '반납됨',
    'system control panel navigation': '시스템 제어판 메뉴',
    'profile & workspaces': '프로필 및 작업 공간',
    'manage your personal details, move between team workspaces, and decide who can access your inventory.': '개인 정보를 관리하고 팀 작업 공간을 전환하며 재고에 접근할 사용자를 설정하세요.',
    'your account details and work information': '계정 및 업무 정보',
    'choose the language used across the system.': '시스템 전체에서 사용할 언어를 선택하세요.',
    'update the personal and work details on your account.': '계정의 개인 정보와 업무 정보를 수정하세요.',
    'confirm your account details and current password.': '계정 정보와 현재 비밀번호를 확인하세요.',
    'enter the invitation code shared by your teammate.': '팀원이 공유한 초대 코드를 입력하세요.',
    'joining adds the shared workspace to your account. you can switch back to your own workspace whenever you like.': '공유 작업 공간이 계정에 추가됩니다. 언제든지 개인 작업 공간으로 다시 전환할 수 있습니다.',
    'this teammate will no longer be able to open this workspace.': '이 팀원은 더 이상 이 작업 공간을 열 수 없습니다.',
    'create an account for the asset management workspace.': '자산 관리 작업 공간 계정을 만드세요.',
    'already registered?': '이미 등록하셨나요?', 'set up your profile': '프로필 설정',
    'new account': '새 계정', 'first name': '이름', 'last name': '성', 'work area / site': '근무 구역 / 현장',
    'employee id / username': '직원 ID / 사용자 이름', 'at least 4 characters. no special characters, uppercase letters, or numbers are required.': '4자 이상 입력하세요. 특수 문자, 대문자 또는 숫자는 필수가 아닙니다.',
    'sign in to your account': '계정에 로그인', 'welcome back': '다시 오신 것을 환영합니다.',
    'access your asset management workspace.': '자산 관리 작업 공간에 접속하세요.',
    'add, edit, and track inventory assets.': '재고 자산을 추가, 수정 및 추적하세요.',
    'database status': '데이터베이스 상태', 'database connection problem': '데이터베이스 연결 문제',
    'system control · workspace settings': '시스템 제어 · 작업 공간 설정',
    'manage navigation, module data, appearance, and language preferences.': '메뉴, 모듈 데이터, 화면 테마 및 언어 설정을 관리합니다.',
    'import multiple modules': '여러 모듈 가져오기',
    'leave empty to auto-generate': '자동 생성하려면 비워 두세요',
    'if provided, this exact table name will be used (letters, numbers, underscore only).': '입력하면 이 테이블 이름을 그대로 사용합니다 (영문자, 숫자, 밑줄만 가능).',
    'system appearance': '시스템 테마', 'color theme': '색상 테마', 'light mode': '라이트 모드',
    'dark mode': '다크 모드', 'use device setting': '기기 설정 사용', 'save appearance': '테마 저장',
    'secure workspace': '보안 작업 공간', 'welcome to your workspace': '작업 공간에 오신 것을 환영합니다',
    'preparing your secure workspace': '보안 작업 공간을 준비하는 중입니다',
    'verifying credentials': '로그인 정보를 확인하는 중', 'opening workspace': '작업 공간을 여는 중',
    'hdjv assistant': 'HDJV 도우미', 'show hdjv assistant in the sidebar': '사이드바에 HDJV 도우미 표시',
    'hide the assistant launcher for everyone in this workspace without deleting it or its chat feature.': '삭제하거나 채팅 기능을 끄지 않고 이 작업 공간의 모든 사용자에게 도우미 실행 버튼을 숨깁니다.',
    'could not load preferences.': '설정을 불러오지 못했습니다.', 'please sign in again.': '다시 로그인해 주세요.',
    'your session has expired. please sign in again.': '세션이 만료되었습니다. 다시 로그인해 주세요.',
    'please sign in again': '다시 로그인해 주세요', 'could not load preferences': '설정을 불러오지 못했습니다',
    'search navigation...': '메뉴 검색...', 'search navigation…': '메뉴 검색…',
    'admin sidebar': '관리자 메뉴', 'admin pages': '관리자 페이지', 'admin help': '관리자 도움말',
    'overview': '개요', 'databases': '데이터베이스', 'users': '사용자', 'activity': '활동',
    'administration console': '관리 콘솔', 'assets monitoring administration': '자산 모니터링 관리',
    'system management / administrator': '시스템 관리 / 관리자', 'system overview': '시스템 개요',
    'manage application data, accounts, and system activity.': '애플리케이션 데이터, 계정 및 시스템 활동을 관리합니다.',
    'connecting': '연결 중', 'updated': '업데이트됨', 'system metrics': '시스템 지표',
    'application schemas visible to this server': '이 서버에서 사용할 수 있는 애플리케이션 스키마',
    'tables & views': '테이블 및 뷰', 'approximate stored rows:': '저장된 행 예상 수:',
    'active accounts': '활성 계정', 'accounts across the system': '시스템 전체 계정',
    'active sessions': '활성 세션', 'successful sign-ins in the last 24 hours': '최근 24시간 내 성공한 로그인',
    'server information': '서버 정보', 'database engine': '데이터베이스 엔진', 'server time': '서버 시간',
    'database footprint': '데이터베이스 크기', 'database recovery': '데이터베이스 복구',
    'checking the latest full-system backup…': '최신 전체 시스템 백업을 확인하는 중…',
    'restore missing databases': '누락된 데이터베이스 복원',
    'recovery restores only databases missing from mysql. existing databases are left unchanged. the restore point is the newest full-system backup.': '복구는 MySQL에서 누락된 데이터베이스만 복원합니다. 기존 데이터베이스는 변경하지 않습니다. 복원 지점은 가장 최근의 전체 시스템 백업입니다.',
    'storage analyzer': '저장 공간 분석', 'estimated storage by database and largest table structures.': '데이터베이스별 예상 저장 공간 및 가장 큰 테이블 구조입니다.',
    'calculating storage distribution…': '저장 공간 분포를 계산하는 중…',
    'review schemas, storage, structure changes, and tracked workspace usage.': '스키마, 저장 공간, 구조 변경 및 작업 공간 사용량을 확인합니다.',
    'find a database or table…': '데이터베이스 또는 테이블 검색…', 'expand all': '모두 펼치기', 'collapse all': '모두 접기',
    'export report': '보고서 내보내기', 'loading database metadata…': '데이터베이스 메타데이터를 불러오는 중…',
    'account profile details from accessible application databases.': '접근 가능한 애플리케이션 데이터베이스의 계정 프로필 정보입니다.',
    'search users, teams, or work areas…': '사용자, 팀 또는 작업 구역 검색…', 'search user accounts': '사용자 계정 검색',
    'filter accounts by status': '상태별 계정 필터', 'all account statuses': '모든 계정 상태',
    'disabled': '사용 중지', 'export csv': 'CSV 내보내기', 'loading user accounts…': '사용자 계정을 불러오는 중…',
    'current passwords cannot be viewed. a temporary password can be issued and must be changed at next sign-in.': '현재 비밀번호는 확인할 수 없습니다. 임시 비밀번호를 발급할 수 있으며 다음 로그인 시 변경해야 합니다.',
    'temporary password for': '임시 비밀번호:', 'copy': '복사', 'dismiss': '닫기',
    'latest sign-in events and recorded actions in the authentication database.': '인증 데이터베이스의 최근 로그인 및 기록된 작업입니다.',
    'recent sign-ins': '최근 로그인', 'loading sign-in activity…': '로그인 활동을 불러오는 중…',
    'user actions': '사용자 작업', 'loading activity records…': '활동 기록을 불러오는 중…',
    'admin archive actions': '관리자 보관 작업', 'loading admin actions…': '관리자 작업을 불러오는 중…',
    'recent event tables are limited to the latest 20 entries. network addresses and sensitive event details are intentionally omitted.': '최근 이벤트 테이블에는 최신 20개 항목만 표시됩니다. 네트워크 주소와 민감한 이벤트 세부 정보는 표시되지 않습니다.',
    'encrypted recovery archives for removed tenant workspaces and tables.': '삭제된 테넌트 작업 공간과 테이블의 암호화된 복구 보관 파일입니다.',
    'loading recovery archives…': '복구 보관 파일을 불러오는 중…', 'refresh archives': '보관 파일 새로고침',
    'restore returns an archive to its original location. permanently deleting an archive cannot be undone.': '복원하면 보관 파일이 원래 위치로 돌아갑니다. 보관 파일을 영구 삭제하면 되돌릴 수 없습니다.',
    'quick tenant archive and restore · admin session expires after 20 minutes idle': '테넌트 빠른 보관 및 복원 · 20분 동안 활동이 없으면 관리자 세션이 만료됩니다.',
    'sign out': '로그아웃', 'refresh data': '데이터 새로고침', 'search databases or tables': '데이터베이스 또는 테이블 검색'
  };
  const documents = new WeakSet();
  const originalText = new WeakMap();
  const appliedText = new WeakMap();
  const originalAttributes = new WeakMap();
  const appliedAttributes = new WeakMap();
  const originalTitles = new WeakMap();
  const appliedTitles = new WeakMap();
  let activeLanguage = 'en';
  const normalize = value => String(value ?? '').replace(/\s+/g, ' ').trim().toLocaleLowerCase();

  function translateValue(value, language, allowTerms = false) {
    if (language !== 'ko') return value;
    const normalized = normalize(value);
    if (!normalized) return value;
    let translated = englishToKorean[normalized];
    if (!translated && allowTerms) {
      translated = String(value);
      const terms = englishToKorean;
      const escapeRegex = term => term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
      for (const [term, replacement] of Object.entries(terms).sort((a, b) => b[0].length - a[0].length)) {
        if (term.length < 3) continue;
        const pattern = new RegExp(`(?<![\\p{L}\\p{N}])${escapeRegex(term)}(?![\\p{L}\\p{N}])`, 'giu');
        translated = translated.replace(pattern, replacement);
      }
      if (translated === String(value)) return value;
    }
    if (!translated) return value;
    const leading = String(value).match(/^\s*/)?.[0] || '';
    const trailing = String(value).match(/\s*$/)?.[0] || '';
    return leading + translated + trailing;
  }

  function translateDocument(doc) {
    if (!doc?.documentElement || !doc.body) return;
    doc.documentElement.lang = activeLanguage === 'ko' ? 'ko' : 'en';
    const walker = doc.createTreeWalker(doc.body, NodeFilter.SHOW_TEXT, {
      acceptNode(node) {
        const parent = node.parentElement;
        if (!parent || parent.closest('script,style,textarea,pre,code,[data-language-ignore]')) return NodeFilter.FILTER_REJECT;
        if (parent.closest('#sidebarUserName,#sidebarUsername,#aiChatMessages,.chat-message,[data-user-content],[contenteditable="true"]')) return NodeFilter.FILTER_REJECT;
        const dataCell = parent.closest('td');
        if (dataCell && !parent.closest('button,a,.badge,.status-badge,[role="button"]')) return NodeFilter.FILTER_REJECT;
        return NodeFilter.FILTER_ACCEPT;
      }
    });
    let node;
    while ((node = walker.nextNode())) {
      const current = node.nodeValue;
      const previousApplied = appliedText.get(node);
      const source = previousApplied !== undefined && current === previousApplied ? originalText.get(node) : current;
      if (source === undefined) continue;
      const semantic = node.parentElement?.closest('h1,h2,h3,h4,h5,h6,button,a,label,th,option,p,small,strong,span,li,summary,legend,figcaption,dt,dd,main,section,article,.card,.panel,.modal-content,.tab,.nav-link,.sub-nav-link,.side-link,.eyebrow,.subtitle,.card-title,.section-title,.section-subtitle,.stat-label,.detail-label,.field,.hint,.status-badge,.empty-state,.empty,.count-label,.table-foot,.type,.message,.dialog-message,.toast-message,.toolbar,.form-label,.form-text,.form-help,.helper-text,.description,.alert');
      originalText.set(node, source);
      const translated = translateValue(source, activeLanguage, Boolean(semantic));
      if (translated !== current) node.nodeValue = translated;
      appliedText.set(node, translated);
    }
    for (const element of doc.querySelectorAll('[placeholder],[title],[aria-label],[alt],button[value],input[type="button"][value],input[type="submit"][value],input[type="reset"][value]')) {
      for (const attr of ['placeholder', 'title', 'aria-label', 'alt', 'value']) {
        if (element.hasAttribute(attr)) {
          if (attr === 'value' && !element.matches('button,input[type="button"],input[type="submit"],input[type="reset"]')) continue;
          const current = element.getAttribute(attr);
          let originals = originalAttributes.get(element);
          let applied = appliedAttributes.get(element);
          if (!originals) { originals = new Map(); originalAttributes.set(element, originals); }
          if (!applied) { applied = new Map(); appliedAttributes.set(element, applied); }
          const source = applied.has(attr) && current === applied.get(attr) ? originals.get(attr) : current;
          originals.set(attr, source);
          const translated = translateValue(source, activeLanguage, true);
          if (translated !== current) element.setAttribute(attr, translated);
          applied.set(attr, translated);
        }
      }
    }
    const currentTitle = doc.title;
    const previousTitle = appliedTitles.get(doc);
    const sourceTitle = previousTitle !== undefined && currentTitle === previousTitle ? originalTitles.get(doc) : currentTitle;
    originalTitles.set(doc, sourceTitle);
    const translatedTitle = translateValue(sourceTitle, activeLanguage, true);
    if (translatedTitle !== currentTitle) doc.title = translatedTitle;
    appliedTitles.set(doc, translatedTitle);
  }

  function observeDocument(doc) {
    if (!doc?.body || documents.has(doc)) return;
    documents.add(doc);
    translateDocument(doc);
    new MutationObserver(() => translateDocument(doc)).observe(doc.body, {subtree:true,childList:true,characterData:true,attributes:true,attributeFilter:['placeholder','title','aria-label','alt','value']});
  }

  function set(language) {
    activeLanguage = language === 'ko' ? 'ko' : 'en';
    try { localStorage.setItem('asset-monitor-language', activeLanguage); } catch (_) {}
    observeDocument(document);
    translateDocument(document);
    document.querySelectorAll('iframe').forEach(frame => {
      try {
        observeDocument(frame.contentDocument);
        translateDocument(frame.contentDocument);
      } catch (_) {}
    });
  }

  window.AssetMonitorLanguage = {
    set,
    attachFrame(frame) { try { observeDocument(frame.contentDocument); } catch (_) {} },
    get() { return activeLanguage; }
  };
  try { activeLanguage = localStorage.getItem('asset-monitor-language') === 'ko' ? 'ko' : 'en'; } catch (_) {}
  document.addEventListener('DOMContentLoaded', () => set(activeLanguage));
})();
